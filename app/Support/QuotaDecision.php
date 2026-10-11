<?php

namespace App\Support;

use App\Enums\QuotaMetric;
use App\Enums\QuotaPeriod;
use Carbon\CarbonInterface;

/**
 * Result of attempting to consume quota for a rate metric.
 *
 * When allowed is false the caller should skip the expensive provider call and
 * treat the refusal as a normal business outcome (Out of quota), not an error.
 */
readonly class QuotaDecision
{
    public function __construct(
        public bool $allowed,
        public ?int $limit,
        public int $used,
        public CarbonInterface $resetsAt,
        public QuotaPeriod $period = QuotaPeriod::Daily,
    ) {}

    /**
     * Human-readable reason for a refused consume, suitable for Activity and flash.
     */
    public function message(QuotaMetric $metric): string
    {
        $label = match ($metric) {
            QuotaMetric::SourceFetches => $this->period->label().' source fetch limit',
            QuotaMetric::Deliveries => $this->period->label().' delivery limit',
            default => $metric->label().' limit',
        };

        $limit = $this->limit ?? 0;
        $resets = $this->period->formatReset($this->resetsAt);

        return "{$label} reached ({$this->used} of {$limit}). Resets at {$resets}.";
    }
}
