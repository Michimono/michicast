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
     * Statuses that mean this route must not be published again on their own.
     * Skipped does not block: a filtered-out item is checked again on later
     * runs, so changing a filter can let it through (#224). Failed is not
     * included: permanent versus transient is decided on the delivery row via
     * ContentDelivery::blocksRepublish().
     */
    public function blocksRepublish(): bool
    {
        return match ($this) {
            self::Pending, self::Delivered => true,
            self::Skipped, self::Failed => false,
        };
    }
}
