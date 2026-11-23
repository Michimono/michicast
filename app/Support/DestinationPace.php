<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * What to do with one delivery once the destination lock is held.
 */
final readonly class DestinationPace
{
    private function __construct(
        public bool $refused,
        public bool $queued,
        public ?CarbonInterface $scheduledFor,
    ) {}

    public static function immediate(): self
    {
        return new self(false, false, null);
    }

    public static function queued(CarbonInterface $scheduledFor): self
    {
        return new self(false, true, $scheduledFor);
    }

    public static function full(): self
    {
        return new self(true, false, null);
    }
}
