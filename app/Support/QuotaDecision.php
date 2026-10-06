<?php

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Result of attempting to consume quota for a rate metric.
 *
 * When allowed is false the caller should skip the expensive provider call.
 * Activity/"Out of quota" stop-run behavior is handled separately (#161).
 */
readonly class QuotaDecision
{
    public function __construct(
        public bool $allowed,
        public ?int $limit,
        public int $used,
        public CarbonInterface $resetsAt,
    ) {}
}
