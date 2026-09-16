<?php

namespace App\Enums;

enum ContentItemLifecycle: string
{
    case Discovered = 'discovered';
    case Normalized = 'normalized';
    case Eligible = 'eligible';
    case Transformed = 'transformed';
    case Delivering = 'delivering';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Discovered => 'Discovered',
            self::Normalized => 'Normalized',
            self::Eligible => 'Eligible',
            self::Transformed => 'Transformed',
            self::Delivering => 'Delivering',
            self::Delivered => 'Delivered',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
        };
    }
}
