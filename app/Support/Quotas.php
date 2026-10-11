<?php

namespace App\Support;

use App\Enums\QuotaMetric;
use App\Enums\QuotaPeriod;
use App\Models\Organization;
use App\Models\OrganizationQuotaOverride;
use App\Models\OrganizationQuotaPeriod;

/**
 * Resolves the quota limit and period for an organization and metric.
 *
 * Limits: override rows win when present (null limit = unlimited). Otherwise
 * the organization's named plan is used, falling back to the configured
 * default plan when the plan name is missing or unknown. Null means unlimited.
 *
 * Periods (rate metrics only): an organization_quota_periods row wins, then
 * the plan's plan_periods entry, then daily.
 */
class Quotas
{
    public function limitFor(Organization $organization, QuotaMetric $metric): ?int
    {
        $override = OrganizationQuotaOverride::query()
            ->where('organization_id', $organization->getKey())
            ->where('metric', $metric)
            ->first();

        if ($override !== null) {
            return $override->limit;
        }

        return $this->planLimit($this->planName($organization), $metric);
    }

    /**
     * Plan-configured limit for the organization, ignoring overrides.
     */
    public function planLimitFor(Organization $organization, QuotaMetric $metric): ?int
    {
        return $this->planLimit($this->planName($organization), $metric);
    }

    /**
     * Effective counting period for a rate metric.
     */
    public function periodFor(Organization $organization, QuotaMetric $metric): QuotaPeriod
    {
        $override = OrganizationQuotaPeriod::query()
            ->where('organization_id', $organization->getKey())
            ->where('metric', $metric)
            ->first();

        return $override->period ?? $this->planPeriodFor($organization, $metric);
    }

    /**
     * Plan-configured period for the organization, ignoring the admin setting.
     */
    public function planPeriodFor(Organization $organization, QuotaMetric $metric): QuotaPeriod
    {
        /** @var array<string, array<string, string|null>> $periods */
        $periods = config('michicast.quotas.plan_periods', []);
        $configured = $periods[$this->planName($organization)][$metric->value] ?? null;

        return (is_string($configured) ? QuotaPeriod::tryFrom($configured) : null) ?? QuotaPeriod::Daily;
    }

    /**
     * Plan name to read limits from: the org's plan when known, else default.
     */
    public function planName(Organization $organization): string
    {
        /** @var array<string, array<string, int|null>> $plans */
        $plans = config('michicast.quotas.plans', []);
        $default = (string) config('michicast.quotas.default_plan', 'free');
        $plan = $organization->plan;

        if (is_string($plan) && $plan !== '' && array_key_exists($plan, $plans)) {
            return $plan;
        }

        return $default;
    }

    /**
     * Configured plan names for admin selects and validation.
     *
     * @return list<string>
     */
    public function knownPlanNames(): array
    {
        /** @var array<string, array<string, int|null>> $plans */
        $plans = config('michicast.quotas.plans', []);

        return array_keys($plans);
    }

    /**
     * Human labels keyed by plan name for admin selects.
     *
     * @return array<string, string>
     */
    public function planOptions(): array
    {
        $options = [];

        foreach ($this->knownPlanNames() as $name) {
            $options[$name] = ucfirst($name);
        }

        return $options;
    }

    private function planLimit(string $planName, QuotaMetric $metric): ?int
    {
        /** @var array<string, array<string, int|null>> $plans */
        $plans = config('michicast.quotas.plans', []);
        $plan = $plans[$planName] ?? [];
        $limit = $plan[$metric->value] ?? null;

        if ($limit === null) {
            return null;
        }

        return (int) $limit;
    }
}
