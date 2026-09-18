<?php

namespace App\Support;

/**
 * Strip credentials and tokens from messages before they reach a Blade view.
 */
class SecretRedactor
{
    /**
     * Patterns that commonly leak bot tokens, bearer secrets, or hex API keys.
     *
     * @var list<string>
     */
    private const PATTERNS = [
        '#\b\d{8,12}:[A-Za-z0-9_-]{20,}\b#',
        '#\b(?:Bearer|Token)\s+[A-Za-z0-9._~\-+/=]{8,}#i',
        '#\b(?:ghp|gho|github_pat)_[A-Za-z0-9_]{20,}\b#',
        '#\bsk-[A-Za-z0-9]{20,}\b#',
        '#\b[A-Fa-f0-9]{32,}\b#',
    ];

    public function redact(?string $message): ?string
    {
        if ($message === null || $message === '') {
            return $message;
        }

        $redacted = $message;

        foreach (self::PATTERNS as $pattern) {
            $redacted = preg_replace($pattern, '[redacted]', $redacted) ?? $redacted;
        }

        return $redacted;
    }
}
