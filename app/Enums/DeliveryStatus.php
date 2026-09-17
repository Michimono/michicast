<?php

namespace App\Enums;

enum DeliveryStatus: string
{
    case Pending = 'pending';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Skipped = 'skipped';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Delivered => 'Delivered',
            self::Failed => 'Failed',
            self::Skipped => 'Skipped',
        };
    }

    /**
     * Statuses that mean this route must not be published again.
     *
     * Pending is included so a crash after claim (and possibly after send)
     * never triggers a second publish. Failed stays retryable for #33.
     */
    public function blocksRepublish(): bool
    {
        return match ($this) {
            self::Pending, self::Delivered, self::Skipped => true,
            self::Failed => false,
        };
    }
}
