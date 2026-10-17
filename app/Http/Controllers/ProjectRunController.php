<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectRun;
use App\Support\SecretRedactor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProjectRunController extends Controller
{
    private const EDITOR_PAGE_SIZE = 8;

    public function __construct(private SecretRedactor $secrets) {}

    public function index(Request $request, Organization $organization, Project $project): View|JsonResponse
    {
        Gate::authorize('view', $project);

        $runs = $project->runs()
            ->latest('started_at')
            ->latest('id')
            ->paginate($request->expectsJson() ? self::EDITOR_PAGE_SIZE : 20)
            ->withQueryString();

        // The pipeline editor shows the latest runs in its activity panel.
        if ($request->expectsJson()) {
            return response()->json([
                'runs' => $runs->getCollection()->map(fn (ProjectRun $run): array => [
                    'id' => $run->getKey(),
                    'started_at' => $run->started_at->toIso8601String(),
                    'finished_at' => $run->finished_at?->toIso8601String(),
                    'is_dry' => $run->is_dry,
                    'status' => $run->status->value,
                    'status_label' => $run->status->label(),
                    'tone' => $run->status->tone(),
                    'items_found' => $run->items_found,
                    'items_new' => $run->items_new,
                    'items_skipped' => $run->items_skipped,
                    'items_delivered' => $run->items_delivered,
                    'items_failed' => $run->items_failed,
                    'url' => route('broadcast-runs.show', [$project, $run]),
                ])->values()->all(),
                'total' => $runs->total(),
                'index_url' => route('broadcast-runs.index', $project),
            ]);
        }

        return view('project-runs.index', [
            'project' => $project,
            'runs' => $runs,
        ]);
    }

    public function show(Organization $organization, Project $project, ProjectRun $run): View
    {
        Gate::authorize('view', $project);

        $run->load([
            'broadcastRuns.broadcast.source',
            'broadcastRuns.broadcast.destination',
            'broadcastRuns.deliveries.contentItem',
            'broadcastRuns.deliveries.destination',
            'broadcastRuns.skips.contentItem',
        ]);

        return view('project-runs.show', [
            'project' => $project,
            'run' => $run,
            'secrets' => $this->secrets,
        ]);
    }
}
