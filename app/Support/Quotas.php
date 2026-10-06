<?php

namespace App\Support;

use App\Enums\QuotaMetric;
use App\Models\Organization;
use App\Models\OrganizationQuotaOverride;

/**
 * Resolves the quota limit for an organization and metric.
 *
 * Override rows win when present (null limit = unlimited). Otherwise the
 * organization's named plan is used, falling back to the configured default
 * plan when the plan name is missing or unknown. Null means unlimited.
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

        return $this->planLimit($this->resolvedPlanName($organization), $metric);
    }

    /**
     * Plan name to read limits from: the org's plan when known, else default.
     */
    private function resolvedPlanName(Organization $organization): string
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
