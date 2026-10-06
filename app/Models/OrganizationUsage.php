<?php

namespace App\Models;

use App\Enums\QuotaMetric;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Per-organization usage of a rate metric for one UTC calendar day.
 *
 * period_start is the UTC date the counter belongs to. Counters reset at
 * UTC midnight (a new row for the next day), not the organization's timezone.
 *
 * @property QuotaMetric $metric
 * @property Carbon $period_start
 * @property int $used
 */
#[Fillable(['organization_id', 'metric', 'period_start', 'used'])]
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
            'period_start' => 'date',
            'used' => 'integer',
        ];
    }
}
