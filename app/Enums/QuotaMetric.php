<?php

namespace App\Enums;

/**
 * Metered resources an organization can be limited on.
 *
 * Rate metrics are counted over a calendar period (daily for fetches and
 * deliveries). Count metrics are a cap on how many records may exist at once.
 */
enum QuotaMetric: string
{
    case SourceFetches = 'source_fetches';
    case Deliveries = 'deliveries';
    case ActiveBroadcasts = 'active_broadcasts';
    case Sources = 'sources';
    case Destinations = 'destinations';
    case Members = 'members';

    /**
     * Whether this metric is a rate over a period rather than a stock count.
     */
    public function isRate(): bool
    {
        return $this->period() !== null;
    }

    /**
     * The period a rate metric is counted over, or null for stock counts.
     */
    public function period(): ?string
    {
        return match ($this) {
            self::SourceFetches, self::Deliveries => 'daily',
            self::ActiveBroadcasts, self::Sources, self::Destinations, self::Members => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::SourceFetches => 'Source fetches',
            self::Deliveries => 'Deliveries',
            self::ActiveBroadcasts => 'Active broadcasts',
            self::Sources => 'Sources',
            self::Destinations => 'Destinations',
            self::Members => 'Members',
        };
    }
}
