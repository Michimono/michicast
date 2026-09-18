<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectRun;
use App\Support\SecretRedactor;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProjectRunController extends Controller
{
    public function __construct(private SecretRedactor $secrets) {}

    public function index(Organization $organization, Project $project): View
    {
        Gate::authorize('view', $project);

        $runs = $project->runs()
            ->latest('started_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

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
