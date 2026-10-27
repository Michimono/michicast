<?php

namespace App\Support\BroadcastFilters;

use App\Enums\BroadcastFilterRuleType;
use App\Models\ContentItem;
use Carbon\CarbonInterface;
use Illuminate\Support\Str;

/**
 * One closed filter rule. Built from the broadcast's JSON; never invent a type
 * outside BroadcastFilterRuleType.
 */
readonly class BroadcastFilterRule
{
    public function __construct(
        public BroadcastFilterRuleType $type,
        public ?string $field = null,
        public ?string $value = null,
        public ?int $hours = null,
    ) {}

    /**
     * @param  array<string, mixed>  $rule
     */
    public static function fromArray(array $rule): ?self
    {
        $type = BroadcastFilterRuleType::tryFrom((string) ($rule['type'] ?? ''));

        if ($type === null) {
            return null;
        }

        $field = isset($rule['field']) && is_string($rule['field']) ? $rule['field'] : null;
        $value = isset($rule['value']) && is_string($rule['value']) ? $rule['value'] : null;
        $hours = isset($rule['hours']) && is_numeric($rule['hours']) ? (int) $rule['hours'] : null;

        return new self($type, $field, $value, $hours);
    }

    public function matches(ContentItem $item, CarbonInterface $now): bool
    {
        return match ($this->type) {
            BroadcastFilterRuleType::Contains => $this->fieldContains($item, true),
            BroadcastFilterRuleType::DoesNotContain => $this->fieldContains($item, false),
            BroadcastFilterRuleType::TitleMatches => $this->titleMatches($item),
            BroadcastFilterRuleType::PublishedAfter => $this->publishedWithinHours($item, $now),
            BroadcastFilterRuleType::PublishedBefore => $this->publishedOlderThanHours($item, $now),
            BroadcastFilterRuleType::AuthorEquals => $this->authorEquals($item),
        };
    }

    /**
     * Skip reason for an item this rule rejected. Says so when the rule could
     * not be checked at all (no publish date, invalid pattern) instead of
     * implying the item failed it on content.
     */
    public function describeFor(ContentItem $item): string
    {
        $timeRule = $this->type === BroadcastFilterRuleType::PublishedAfter
            || $this->type === BroadcastFilterRuleType::PublishedBefore;

        if ($timeRule && $item->published_at === null) {
            return 'The source gave no publish date, so time filters can’t match it';
        }

        if ($this->type === BroadcastFilterRuleType::TitleMatches
            && ($this->value ?? '') !== ''
            && ! BoundedRegex::isValid((string) $this->value)) {
            return sprintf('The pattern “%s” isn’t a valid regular expression, so nothing matches it', $this->value);
        }

        return $this->describe();
    }

    /**
     * Sentence naming this rule for a skip reason.
     */
    public function describe(): string
    {
        return match ($this->type) {
            BroadcastFilterRuleType::Contains => sprintf(
                '%s does not contain “%s”',
                $this->fieldLabel(),
                $this->value ?? '',
            ),
            BroadcastFilterRuleType::DoesNotContain => sprintf(
                '%s contains “%s”',
                $this->fieldLabel(),
                $this->value ?? '',
            ),
            BroadcastFilterRuleType::TitleMatches => sprintf(
                '%s does not match “%s”',
                $this->fieldLabel(),
                $this->value ?? '',
            ),
            BroadcastFilterRuleType::PublishedAfter => sprintf(
                'Not published within the last %d hours',
                $this->hours ?? 0,
            ),
            BroadcastFilterRuleType::PublishedBefore => sprintf(
                'Not published more than %d hours ago',
                $this->hours ?? 0,
            ),
            BroadcastFilterRuleType::AuthorEquals => sprintf(
                '%s is not “%s”',
                $this->field === null ? 'Author' : $this->fieldLabel(),
                $this->value ?? '',
            ),
        };
    }

    private function fieldContains(ContentItem $item, bool $mustContain): bool
    {
        $haystack = $this->textOf($item);

        $needle = (string) ($this->value ?? '');

        if ($needle === '') {
            return ! $mustContain;
        }

        $found = Str::contains(Str::lower($haystack), Str::lower($needle));

        return $mustContain ? $found : ! $found;
    }

    private function titleMatches(ContentItem $item): bool
    {
        $pattern = (string) ($this->value ?? '');

        if ($pattern === '' || ! BoundedRegex::isValid($pattern)) {
            return false;
        }

        return BoundedRegex::matches($pattern, $this->textOf($item));
    }

    private function publishedWithinHours(ContentItem $item, CarbonInterface $now): bool
    {
        if ($item->published_at === null || $this->hours === null || $this->hours < 1) {
            return false;
        }

        return $item->published_at->gte($now->copy()->subHours($this->hours));
    }

    private function publishedOlderThanHours(ContentItem $item, CarbonInterface $now): bool
    {
        if ($item->published_at === null || $this->hours === null || $this->hours < 1) {
            return false;
        }

        return $item->published_at->lte($now->copy()->subHours($this->hours));
    }

    private function authorEquals(ContentItem $item): bool
    {
        $expected = trim((string) ($this->value ?? ''));
        $actual = trim($this->field === null || $this->field === 'author'
            ? (string) ($item->author ?? '')
            : $this->textOf($item));

        if ($expected === '') {
            return false;
        }

        return Str::lower($actual) === Str::lower($expected);
    }

    private function textOf(ContentItem $item): string
    {
        return match ($this->field) {
            'body' => (string) ($item->body ?? ''),
            'author' => (string) ($item->author ?? ''),
            'link' => (string) ($item->canonical_url ?? ''),
            default => (string) ($item->title ?? ''),
        };
    }

    private function fieldLabel(): string
    {
        return match ($this->field) {
            'body' => 'Body',
            'author' => 'Author',
            'link' => 'Link',
            'published_at' => 'Published',
            default => 'Title',
        };
    }
}
