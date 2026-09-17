<?php

namespace App\Support\BroadcastFilters;

/**
 * Whether a content item passes a broadcast's filters, and when it does not,
 * the human-readable reason the run history will show.
 */
readonly class FilterDecision
{
    public function __construct(
        public bool $passed,
        public ?string $skipReason = null,
    ) {}

    public static function pass(): self
    {
        return new self(true);
    }

    public static function skip(string $reason): self
    {
        return new self(false, $reason);
    }
}
