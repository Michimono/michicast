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
    case IsSet = 'is_set';
    case IsNotSet = 'is_not_set';

    public function label(): string
    {
        return match ($this) {
            self::Contains => 'Title or body contains',
            self::DoesNotContain => 'Title or body does not contain',
            self::TitleMatches => 'Title matches pattern',
            self::PublishedAfter => 'Published within the last',
            self::PublishedBefore => 'Published more than',
            self::AuthorEquals => 'Author equals',
            self::IsSet => 'Is set',
            self::IsNotSet => 'Is not set',
        };
    }

    public function needsField(): bool
    {
        return match ($this) {
            self::Contains, self::DoesNotContain, self::IsSet, self::IsNotSet => true,
            default => false,
        };
    }

    /**
     * Presence rules name a field and nothing else. The field may be any
     * one that reaches the filter, not only the shared title and body.
     */
    public function checksPresence(): bool
    {
        return match ($this) {
            self::IsSet, self::IsNotSet => true,
            default => false,
        };
    }

    public function needsValue(): bool
    {
        return match ($this) {
            self::PublishedAfter, self::PublishedBefore, self::IsSet, self::IsNotSet => false,
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
