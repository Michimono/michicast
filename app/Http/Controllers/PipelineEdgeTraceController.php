<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\PipelineEdgeTrace;
use App\Models\Project;
use App\Support\Pipeline\PipelineTraceRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * What the latest dry run carried across one connection of a visual
 * broadcast. Read-only: traces are written by the dry run itself.
 */
class PipelineEdgeTraceController extends Controller
{
    public function __construct(private PipelineTraceRecorder $traces) {}

    public function show(Request $request, Organization $organization, Project $project, string $edge): JsonResponse
    {
        Gate::authorize('view', $project);
        abort_unless($project->isVisual(), 404);

        $query = PipelineEdgeTrace::query()
            ->where('project_id', $project->getKey())
            ->where('edge_id', $edge)
            ->orderByDesc('project_run_id');

        // Pin to the run the editor is looking at, so a slow response for an
        // older test cannot pass itself off as the newest one.
        if ($request->filled('run')) {
            $query->where('project_run_id', $request->integer('run'));
        }

        $trace = $query->first();

        return response()->json([
            'edge_id' => $edge,
            'trace' => $trace === null ? null : $this->traces->toArray($trace),
        ]);
    }
}
