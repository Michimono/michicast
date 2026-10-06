<?php

namespace App\Models;

use App\Enums\QuotaMetric;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-organization override of a plan quota limit.
 *
 * A null limit means unlimited for that metric. Absence of a row means the
 * organization's plan (or the default plan) applies.
 *
 * @property QuotaMetric $metric
 * @property int|null $limit
 */
#[Fillable(['organization_id', 'metric', 'limit', 'note'])]
class OrganizationQuotaOverride extends Model
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
            'limit' => 'integer',
        ];
    }
}
