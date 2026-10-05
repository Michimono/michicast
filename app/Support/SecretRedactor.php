<?php

namespace App\Support;

/**
 * The one place credentials are stripped from text before it is persisted,
 * rendered or logged. Known credential values are remembered for the current
 * request or command, so a secret is caught even when it matches no pattern.
 */
class SecretRedactor
{
    public const REPLACEMENT = '[redacted]';

    /**
     * Values shorter than this are too likely to be ordinary words to replace
     * everywhere they appear.
     */
    private const MIN_SECRET_LENGTH = 6;

    /**
     * Patterns that commonly leak bot tokens, bearer secrets, or hex API keys.
     * The bot token pattern has no leading word boundary so it also matches the
     * "/bot<token>/" form Telegram puts in request URLs.
     *
     * @var list<string>
     */
    private const PATTERNS = [
        '#(?<!\d)\d{8,12}:[A-Za-z0-9_-]{20,}#',
        '#\b(?:Bearer|Token)\s+[A-Za-z0-9._~\-+/=]{8,}#i',
        '#\b(?:ghp|gho|github_pat)_[A-Za-z0-9_]{20,}\b#',
        '#\bsk-[A-Za-z0-9]{20,}\b#',
        '#\b[A-Fa-f0-9]{32,}\b#',
    ];

    /**
     * @var array<string, true>
     */
    private array $known = [];

    /**
     * Remember credential values so later redaction catches them verbatim.
     *
     * @param  array<array-key, mixed>  $credentials
     */
    public function remember(#[\SensitiveParameter] array $credentials): void
    {
        foreach ($this->secretValues($credentials) as $secret) {
            $this->known[$secret] = true;
        }
    }

    /**
     * @param  array<array-key, mixed>  $secrets  Extra values to strip besides the remembered ones.
     */
    public function redact(?string $message, #[\SensitiveParameter] array $secrets = []): ?string
    {
        if ($message === null || $message === '') {
            return $message;
        }

        $redacted = str_replace($this->variants([...array_keys($this->known), ...$this->secretValues($secrets)]), self::REPLACEMENT, $message);

        foreach (self::PATTERNS as $pattern) {
            $redacted = preg_replace($pattern, self::REPLACEMENT, $redacted) ?? $redacted;
        }

        return $redacted;
    }

    /**
     * Show a stored secret only as a short suffix for recognition. Short
     * secrets show no characters at all.
     */
    public function mask(#[\SensitiveParameter] mixed $secret): ?string
    {
        if (! is_string($secret) || $secret === '') {
            return null;
        }

        return mb_strlen($secret) >= 12 ? '••••'.mb_substr($secret, -4) : '••••';
    }

    /**
     * @param  array<array-key, mixed>  $values
     * @return list<string>
     */
    private function secretValues(#[\SensitiveParameter] array $values): array
    {
        $secrets = [];

        array_walk_recursive($values, function (mixed $value) use (&$secrets): void {
            if (is_string($value) && mb_strlen(trim($value)) >= self::MIN_SECRET_LENGTH) {
                $secrets[] = trim($value);
            }
        });

        return $secrets;
    }

    /**
     * The raw secret plus the forms it takes inside a URL or a JSON log line,
     * longest first so a secret containing another is replaced whole.
     *
     * @param  list<string>  $secrets
     * @return list<string>
     */
    private function variants(#[\SensitiveParameter] array $secrets): array
    {
        $variants = [];

        foreach ($secrets as $secret) {
            $variants[] = $secret;
            $variants[] = rawurlencode($secret);
            $variants[] = urlencode($secret);
            $variants[] = substr((string) json_encode($secret, JSON_UNESCAPED_UNICODE), 1, -1);
        }

        $variants = array_values(array_unique(array_filter($variants, fn (string $variant): bool => $variant !== '')));
        usort($variants, fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        return $variants;
    }
}
