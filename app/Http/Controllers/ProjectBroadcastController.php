<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBroadcastRequest;
use App\Models\Broadcast;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProjectBroadcastController extends Controller
{
    public function index(Organization $organization, Project $project): View
    {
        Gate::authorize('viewAny', [Broadcast::class, $project]);

        return view('project-broadcasts.index', [
            'project' => $project,
            'broadcasts' => $project->broadcasts()
                ->notArchived()
                ->with(['source', 'destination'])
                ->oldest('id')
                ->get(),
            'archived' => $project->broadcasts()
                ->whereNotNull('archived_at')
                ->with(['source', 'destination'])
                ->oldest('id')
                ->get(),
        ]);
    }

    public function create(Organization $organization, Project $project): View
    {
        Gate::authorize('create', [Broadcast::class, $project]);

        return view('project-broadcasts.create', [
            'project' => $project,
            'sources' => $this->sourceOptions($project),
            'destinations' => $this->destinationOptions($project),
        ]);
    }

    public function store(StoreBroadcastRequest $request, Organization $organization, Project $project): RedirectResponse
    {
        $project->broadcasts()->create([
            'name' => $request->name(),
            'source_id' => $request->sourceId(),
            'destination_id' => $request->destinationId(),
        ]);

        return redirect()
            ->route('project-broadcasts.index', $project)
            ->with('success', 'The broadcast has been added.');
    }

    /**
     * @return array<int, string>
     */
    private function sourceOptions(Project $project): array
    {
        return $project->sources()
            ->notArchived()
            ->oldest('id')
            ->pluck('name', 'id')
            ->all();
    }

    /**
     * @return array<int, string>
     */
    private function destinationOptions(Project $project): array
    {
        return $project->destinations()
            ->notArchived()
            ->oldest('id')
            ->pluck('name', 'id')
            ->all();
    }
}
