<?php

namespace App\Models;

use App\Enums\RunStatus;
use Database\Factories\BroadcastRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One broadcast's work inside a project run.
 *
 * @property RunStatus $status
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property string|null $error_detail
 */
#[Fillable([
    'project_run_id',
    'broadcast_id',
    'status',
    'started_at',
    'finished_at',
    'items_found',
    'items_new',
    'items_skipped',
    'items_delivered',
    'items_failed',
    'error_detail',
])]
class BroadcastRun extends Model
{
    /** @use HasFactory<BroadcastRunFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'running',
        'items_found' => 0,
        'items_new' => 0,
        'items_skipped' => 0,
        'items_delivered' => 0,
        'items_failed' => 0,
    ];

    /**
     * @return BelongsTo<ProjectRun, $this>
     */
    public function projectRun(): BelongsTo
    {
        return $this->belongsTo(ProjectRun::class);
    }

    /**
     * @return BelongsTo<Broadcast, $this>
     */
    public function broadcast(): BelongsTo
    {
        return $this->belongsTo(Broadcast::class);
    }

    /**
     * @return HasMany<ContentDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(ContentDelivery::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => RunStatus::class,
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'items_found' => 'integer',
            'items_new' => 'integer',
            'items_skipped' => 'integer',
            'items_delivered' => 'integer',
            'items_failed' => 'integer',
        ];
    }
}
