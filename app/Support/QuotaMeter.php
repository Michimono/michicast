<?php

namespace App\Support;

use App\Enums\QuotaMetric;
use App\Enums\QuotaPeriod;
use App\Models\Organization;
use App\Models\OrganizationUsage;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Atomically meters rate-quota usage per organization.
 *
 * Each organization and metric counts over a QuotaPeriod (daily by default;
 * see Quotas::periodFor). Periods are UTC calendar periods: period_start is
 * the UTC date the day, Monday-week or month began, and counters reset at the
 * next boundary (resetsAt). Rows are keyed by period and period_start, so a
 * period change starts a fresh counter instead of reinterpreting old rows.
 * Usage is stored even when the limit is null (unlimited) so remaining() and
 * member views can still report consumption.
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

        $period = $this->quotas->periodFor($organization, $metric);
        $periodStart = $this->currentPeriodStart($period);
        $resetsAt = $this->resetsAt($periodStart, $period);
        $limit = $this->quotas->limitFor($organization, $metric);

        $this->ensureRow($organization, $metric, $period, $periodStart);

        if ($limit === null) {
            $this->row($organization, $metric, $period, $periodStart)->update([
                'used' => DB::raw('used + '.(int) $amount),
                'updated_at' => now(),
            ]);

            return new QuotaDecision(
                allowed: true,
                limit: null,
                used: $this->usedIn($organization, $metric, $period, $periodStart),
                resetsAt: $resetsAt,
                period: $period,
            );
        }

        $affected = $this->row($organization, $metric, $period, $periodStart)
            ->where('used', '<=', $limit - $amount)
            ->update([
                'used' => DB::raw('used + '.(int) $amount),
                'updated_at' => now(),
            ]);

        return new QuotaDecision(
            allowed: $affected > 0,
            limit: $limit,
            used: $this->usedIn($organization, $metric, $period, $periodStart),
            resetsAt: $resetsAt,
            period: $period,
        );
    }

    /**
     * Usage in the organization's current period for the metric, or in the
     * period starting at $periodStart when given.
     */
    public function used(Organization $organization, QuotaMetric $metric, ?CarbonInterface $periodStart = null): int
    {
        $period = $this->quotas->periodFor($organization, $metric);

        return $this->usedIn($organization, $metric, $period, $periodStart ?? $this->currentPeriodStart($period));
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

    public function currentPeriodStart(QuotaPeriod $period = QuotaPeriod::Daily): CarbonImmutable
    {
        return $period->startOf(CarbonImmutable::now('UTC'));
    }

    public function resetsAt(?CarbonInterface $periodStart = null, QuotaPeriod $period = QuotaPeriod::Daily): CarbonImmutable
    {
        return $period->resetsAt($periodStart ?? $this->currentPeriodStart($period));
    }

    /**
     * Effective counting period for this organization's metric.
     */
    public function periodFor(Organization $organization, QuotaMetric $metric): QuotaPeriod
    {
        return $this->quotas->periodFor($organization, $metric);
    }

    /**
     * Current period start for this organization's metric.
     */
    public function periodStartFor(Organization $organization, QuotaMetric $metric): CarbonImmutable
    {
        return $this->currentPeriodStart($this->quotas->periodFor($organization, $metric));
    }

    /**
     * When this organization's current period for the metric resets.
     */
    public function resetsAtFor(Organization $organization, QuotaMetric $metric): CarbonImmutable
    {
        $period = $this->quotas->periodFor($organization, $metric);

        return $this->resetsAt($this->currentPeriodStart($period), $period);
    }

    /**
     * Zero the organization's current-period usage counter for a rate metric.
     *
     * Returns the previous used value. Creates the period row when missing.
     */
    public function resetCurrentPeriod(Organization $organization, QuotaMetric $metric): int
    {
        if (! $metric->isRate()) {
            throw new \InvalidArgumentException('Only rate metrics have a current period to reset.');
        }

        $period = $this->quotas->periodFor($organization, $metric);
        $periodStart = $this->currentPeriodStart($period);
        $previous = $this->usedIn($organization, $metric, $period, $periodStart);

        $this->ensureRow($organization, $metric, $period, $periodStart);

        $this->row($organization, $metric, $period, $periodStart)->update([
            'used' => 0,
            'updated_at' => now(),
        ]);

        return $previous;
    }

    private function usedIn(Organization $organization, QuotaMetric $metric, QuotaPeriod $period, CarbonInterface $periodStart): int
    {
        $row = $this->row($organization, $metric, $period, $periodStart)->first();

        return $row === null ? 0 : $row->used;
    }

    /**
     * @return Builder<OrganizationUsage>
     */
    private function row(Organization $organization, QuotaMetric $metric, QuotaPeriod $period, CarbonInterface $periodStart): Builder
    {
        return OrganizationUsage::query()
            ->where('organization_id', $organization->getKey())
            ->where('metric', $metric)
            ->where('period', $period)
            ->whereDate('period_start', $periodStart->toDateString());
    }

    private function ensureRow(Organization $organization, QuotaMetric $metric, QuotaPeriod $period, CarbonInterface $periodStart): void
    {
        OrganizationUsage::query()->insertOrIgnore([
            'organization_id' => $organization->getKey(),
            'metric' => $metric->value,
            'period' => $period->value,
            'period_start' => $periodStart->toDateString(),
            'used' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
