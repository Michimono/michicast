<?php

namespace App\View\Components;

use App\Enums\QuotaMetric;
use App\Enums\QuotaPeriod;
use App\Models\Organization;
use App\Support\QuotaUsage;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

/**
 * Settings card: plan name, each metric's used/limit, and for rate metrics
 * the counting period and reset time.
 */
class OrganizationUsageCard extends Component
{
    public string $planName;

    /**
     * @var list<array{
     *     metric: QuotaMetric,
     *     label: string,
     *     used: int,
     *     limit: int|null,
     *     exhausted: bool,
     *     period: QuotaPeriod|null,
     *     resets_at: CarbonInterface|null
     * }>
     */
    public array $rows;

    public function __construct(
        public Organization $organization,
        QuotaUsage $usage,
    ) {
        $this->planName = $usage->planName($organization);
        $this->rows = $usage->rows($organization);
    }

    public function render(): View
    {
        return view('components.organization-usage-card');
    }
}
