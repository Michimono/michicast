<?php

namespace App\Models;

use App\Enums\RunStatus;
use Database\Factories\ProjectRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One execution of a project by the dispatcher.
 *
 * @property RunStatus $status
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 */
#[Fillable([
    'project_id',
    'status',
    'started_at',
    'finished_at',
    'items_found',
    'items_new',
    'items_skipped',
    'items_delivered',
    'items_failed',
])]
class ProjectRun extends Model
{
    /** @use HasFactory<ProjectRunFactory> */
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
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return HasMany<BroadcastRun, $this>
     */
    public function broadcastRuns(): HasMany
    {
        return $this->hasMany(BroadcastRun::class);
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
