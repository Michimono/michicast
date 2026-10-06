<?php

namespace App\Support;

use App\Enums\QuotaMetric;
use App\Models\Organization;
use Carbon\CarbonInterface;

/**
 * Read-only organization quota usage for member-facing views.
 *
 * Rate metrics come from QuotaMeter (UTC daily counters). Stock metrics come
 * from ResourceQuotas (current counts). Limits resolve through Quotas.
 */
class QuotaUsage
{
    /**
     * Volume metrics that stop broadcasts when exhausted.
     *
     * @var list<QuotaMetric>
     */
    public const VOLUME_METRICS = [
        QuotaMetric::SourceFetches,
        QuotaMetric::Deliveries,
    ];

    public function __construct(
        private Quotas $quotas,
        private QuotaMeter $meter,
        private ResourceQuotas $resourceQuotas,
    ) {}

    /**
     * Plan name shown on the Usage card (resolved the same way limits are).
     */
    public function planName(Organization $organization): string
    {
        return $this->quotas->planName($organization);
    }

    /**
     * One row per QuotaMetric: used, limit (null = unlimited), and daily reset.
     *
     * @return list<array{
     *     metric: QuotaMetric,
     *     label: string,
     *     used: int,
     *     limit: int|null,
     *     exhausted: bool,
     *     resets_at: CarbonInterface|null
     * }>
     */
    public function rows(Organization $organization): array
    {
        $resetsAt = $this->meter->resetsAt();
        $rows = [];

        foreach (QuotaMetric::cases() as $metric) {
            $limit = $this->quotas->limitFor($organization, $metric);
            $used = $metric->isRate()
                ? $this->meter->used($organization, $metric)
                : $this->resourceQuotas->count($organization, $metric);

            $rows[] = [
                'metric' => $metric,
                'label' => $metric->label(),
                'used' => $used,
                'limit' => $limit,
                'exhausted' => $limit !== null && $used >= $limit,
                'resets_at' => $metric->isRate() ? $resetsAt : null,
            ];
        }

        return $rows;
    }

    /**
     * Whether any volume metric is at or past its limit.
     */
    public function volumeExhausted(Organization $organization): bool
    {
        foreach (self::VOLUME_METRICS as $metric) {
            $limit = $this->quotas->limitFor($organization, $metric);

            if ($limit === null) {
                continue;
            }

            if ($this->meter->used($organization, $metric) >= $limit) {
                return true;
            }
        }

        return false;
    }
}
