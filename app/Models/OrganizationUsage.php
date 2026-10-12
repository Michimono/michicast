<?php

namespace App\Models;

use App\Enums\QuotaMetric;
use App\Enums\QuotaPeriod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-organization usage of a rate metric for one UTC calendar period.
 *
 * period is the QuotaPeriod the row counts (daily, weekly, monthly) and
 * period_start the UTC date that period began (the day, Monday or 1st). A new
 * period gets a new row; the organization's timezone does not move boundaries.
 *
 * @property QuotaMetric $metric
 * @property QuotaPeriod $period
 * @property Carbon $period_start
 * @property int $used
 */
#[Fillable(['organization_id', 'metric', 'period', 'period_start', 'used'])]
class OrganizationUsage extends Model
{
    protected $table = 'organization_usage';

    /**
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metric' => QuotaMetric::class,
            'period' => QuotaPeriod::class,
            'period_start' => 'date',
            'used' => 'integer',
        ];
    }
}
