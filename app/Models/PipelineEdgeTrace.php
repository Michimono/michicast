<?php

namespace App\Models;

use App\Enums\PipelineTraceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The items a dry run carried across one connection of a visual broadcast.
 *
 * The sample is bounded (see PipelineTraceRecorder) and already redacted, so
 * a row can be handed to the editor as it is. A trace belongs to the project
 * through its run; the project id is denormalized so the editor's lookup of
 * "the latest trace for this edge" is one indexed query.
 *
 * @property PipelineTraceStatus $status
 * @property list<array<string, mixed>>|null $items
 */
#[Fillable([
    'project_id',
    'project_run_id',
    'edge_id',
    'from_node_id',
    'to_node_id',
    'status',
    'items_in',
    'items_out',
    'summary',
    'items',
])]
class PipelineEdgeTrace extends Model
{
    /**
     * @return BelongsTo<Project, $this>
     */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /**
     * @return BelongsTo<ProjectRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(ProjectRun::class, 'project_run_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PipelineTraceStatus::class,
            'items_in' => 'integer',
            'items_out' => 'integer',
            'items' => 'array',
        ];
    }
}
