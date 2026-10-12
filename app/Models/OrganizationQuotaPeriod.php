<?php

namespace App\Models;

use App\Enums\QuotaMetric;
use App\Enums\QuotaPeriod;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-organization period for a rate quota, set by a platform admin.
 *
 * Absence of a row means the plan's configured period (or daily) applies.
 * Independent of limit overrides, so period and limit change separately.
 *
 * @property QuotaMetric $metric
 * @property QuotaPeriod $period
 */
#[Fillable(['organization_id', 'metric', 'period'])]
class OrganizationQuotaPeriod extends Model
{
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
        ];
    }
}
