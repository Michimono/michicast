<?php

namespace App\Support\BroadcastTransforms;

use App\Enums\BroadcastTransformRuleType;
use App\Support\BroadcastFilters\BoundedRegex;
use Illuminate\Support\Str;

/**
 * One formatting rule applied after template substitution, in list order.
 */
readonly class BroadcastTransformRule
{
    public function __construct(
        public BroadcastTransformRuleType $type,
        public ?string $value = null,
        public ?int $length = null,
        public ?string $pattern = null,
        public ?string $replacement = null,
    ) {}

    /**
     * @param  array<string, mixed>  $rule
     */
    public static function fromArray(array $rule): ?self
    {
        $type = BroadcastTransformRuleType::tryFrom((string) ($rule['type'] ?? ''));

        if ($type === null) {
            return null;
        }

        return new self(
            type: $type,
            value: isset($rule['value']) && is_string($rule['value']) ? $rule['value'] : null,
            length: isset($rule['length']) && is_numeric($rule['length']) ? (int) $rule['length'] : null,
            pattern: isset($rule['pattern']) && is_string($rule['pattern']) ? $rule['pattern'] : null,
            replacement: isset($rule['replacement']) && is_string($rule['replacement']) ? $rule['replacement'] : null,
        );
    }

    public function apply(string $text): string
    {
        return match ($this->type) {
            BroadcastTransformRuleType::StripHtml => $this->stripHtml($text),
            BroadcastTransformRuleType::Truncate => $this->truncate($text),
            BroadcastTransformRuleType::Prepend => ($this->value ?? '').$text,
            BroadcastTransformRuleType::Append => $text.($this->value ?? ''),
            BroadcastTransformRuleType::Replace => $this->replace($text),
        };
    }

    private function stripHtml(string $text): string
    {
        $plain = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/[ \t]+/u', ' ', $plain) ?? $plain);
    }

    private function truncate(string $text): string
    {
        $length = $this->length ?? 0;

        if ($length < 1) {
            return $text;
        }

        return (string) Str::of($text)->limit($length, preserveWords: true);
    }

    private function replace(string $text): string
    {
        $pattern = (string) ($this->pattern ?? '');

        if ($pattern === '' || ! BoundedRegex::isValid($pattern)) {
            return $text;
        }

        return BoundedRegex::replace($pattern, (string) ($this->replacement ?? ''), $text);
    }
}
