<?php

namespace App\Enums;

enum BroadcastFilterRuleType: string
{
    case Contains = 'contains';
    case DoesNotContain = 'does_not_contain';
    case TitleMatches = 'title_matches';
    case PublishedAfter = 'published_after';
    case PublishedBefore = 'published_before';
    case AuthorEquals = 'author_equals';

    public function label(): string
    {
        return match ($this) {
            self::Contains => 'Title or body contains',
            self::DoesNotContain => 'Title or body does not contain',
            self::TitleMatches => 'Title matches pattern',
            self::PublishedAfter => 'Published within the last',
            self::PublishedBefore => 'Published more than',
            self::AuthorEquals => 'Author equals',
        };
    }

    public function needsField(): bool
    {
        return match ($this) {
            self::Contains, self::DoesNotContain => true,
            default => false,
        };
    }

    public function needsValue(): bool
    {
        return match ($this) {
            self::PublishedAfter, self::PublishedBefore => false,
            default => true,
        };
    }

    public function needsHours(): bool
    {
        return match ($this) {
            self::PublishedAfter, self::PublishedBefore => true,
            default => false,
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    public static function fieldOptions(): array
    {
        return [
            'title' => 'Title',
            'body' => 'Body',
        ];
    }
}
