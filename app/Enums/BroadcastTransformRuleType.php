<?php

namespace App\Enums;

enum BroadcastTransformRuleType: string
{
    case StripHtml = 'strip_html';
    case Truncate = 'truncate';
    case Prepend = 'prepend';
    case Append = 'append';
    case Replace = 'replace';

    public function label(): string
    {
        return match ($this) {
            self::StripHtml => 'Strip HTML',
            self::Truncate => 'Truncate to length',
            self::Prepend => 'Prepend text',
            self::Append => 'Append text',
            self::Replace => 'Replace by pattern',
        };
    }

    public function needsLength(): bool
    {
        return $this === self::Truncate;
    }

    public function needsValue(): bool
    {
        return match ($this) {
            self::Prepend, self::Append => true,
            default => false,
        };
    }

    public function needsPattern(): bool
    {
        return $this === self::Replace;
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
}
