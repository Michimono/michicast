<?php

namespace App\Support;

use App\Enums\QuotaMetric;
use App\Models\Organization;
use App\Models\OrganizationUsage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Atomically meters rate-quota usage per organization.
 *
 * Periods are UTC calendar days: period_start is the UTC date, and counters
 * reset at UTC midnight (resetsAt). Organization timezone does not affect
 * the period boundary. Usage is stored even when the limit is null (unlimited)
 * so remaining() and member views can still report consumption.
 *
 * consume() uses a conditional UPDATE so concurrent Horizon workers cannot
 * push used past the limit.
 */
class QuotaMeter
{
    public function __construct(private Quotas $quotas) {}

    public function consume(Organization $organization, QuotaMetric $metric, int $amount = 1): QuotaDecision
    {
        if ($amount < 1) {
            throw new \InvalidArgumentException('QuotaMeter::consume amount must be at least 1.');
        }

        $periodStart = $this->currentPeriodStart();
        $resetsAt = $this->resetsAt($periodStart);
        $limit = $this->quotas->limitFor($organization, $metric);

        $this->ensureRow($organization, $metric, $periodStart);

        if ($limit === null) {
            $used = $this->increment($organization, $metric, $periodStart, $amount);

            return new QuotaDecision(allowed: true, limit: null, used: $used, resetsAt: $resetsAt);
        }

        $affected = OrganizationUsage::query()
            ->where('organization_id', $organization->getKey())
            ->where('metric', $metric)
            ->whereDate('period_start', $periodStart->toDateString())
            ->where('used', '<=', $limit - $amount)
            ->update([
                'used' => DB::raw('used + '.(int) $amount),
                'updated_at' => now(),
            ]);

        $used = $this->used($organization, $metric, $periodStart);

        return new QuotaDecision(
            allowed: $affected > 0,
            limit: $limit,
            used: $used,
            resetsAt: $resetsAt,
        );
    }

    public function used(Organization $organization, QuotaMetric $metric, ?CarbonInterface $periodStart = null): int
    {
        $periodStart ??= $this->currentPeriodStart();

        $row = OrganizationUsage::query()
            ->where('organization_id', $organization->getKey())
            ->where('metric', $metric)
            ->whereDate('period_start', $periodStart->toDateString())
            ->first();

        if ($row === null) {
            return 0;
        }

        return $row->used;
    }

    public function remaining(Organization $organization, QuotaMetric $metric): ?int
    {
        $limit = $this->quotas->limitFor($organization, $metric);

        if ($limit === null) {
            return null;
        }

        return max(0, $limit - $this->used($organization, $metric));
    }

    /**
     * Delete usage rows whose period started more than $days ago (UTC).
     */
    public function pruneOlderThan(int $days = 90): int
    {
        $cutoff = CarbonImmutable::now('UTC')->subDays($days)->toDateString();

        return OrganizationUsage::query()
            ->whereDate('period_start', '<', $cutoff)
            ->delete();
    }

    public function currentPeriodStart(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->startOfDay();
    }

    public function resetsAt(?CarbonInterface $periodStart = null): CarbonImmutable
    {
        $periodStart ??= $this->currentPeriodStart();

        return CarbonImmutable::parse($periodStart->toDateString(), 'UTC')->addDay()->startOfDay();
    }

    private function ensureRow(Organization $organization, QuotaMetric $metric, CarbonInterface $periodStart): void
    {
        OrganizationUsage::query()->insertOrIgnore([
            'organization_id' => $organization->getKey(),
            'metric' => $metric->value,
            'period_start' => $periodStart->toDateString(),
            'used' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function increment(Organization $organization, QuotaMetric $metric, CarbonInterface $periodStart, int $amount): int
    {
        OrganizationUsage::query()
            ->where('organization_id', $organization->getKey())
            ->where('metric', $metric)
            ->whereDate('period_start', $periodStart->toDateString())
            ->update([
                'used' => DB::raw('used + '.(int) $amount),
                'updated_at' => now(),
            ]);

        return $this->used($organization, $metric, $periodStart);
    }
}
