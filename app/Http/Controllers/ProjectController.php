<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Support\ProjectHealthResolver;
use App\Support\ProjectScheduleForm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(Organization $organization): View
    {
        Gate::authorize('viewAny', [Project::class, $organization]);

        return view('projects.index', [
            'organization' => $organization,
            'projects' => $organization->projects()->notArchived()->oldest('id')->get(),
        ]);
    }

    public function create(Organization $organization): View
    {
        Gate::authorize('create', [Project::class, $organization]);

        return view('projects.create');
    }

    public function store(StoreProjectRequest $request, Organization $organization): RedirectResponse
    {
        $name = $request->string('name')->toString();

        $project = $organization->projects()->create([
            'name' => $name,
            'slug' => Project::uniqueSlugFrom($name, $organization),
            'description' => $request->nullableDescription(),
        ]);

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'The project has been created.');
    }

    public function show(
        Organization $organization,
        Project $project,
        ProjectHealthResolver $health,
        ProjectScheduleForm $scheduleForm,
    ): View {
        Gate::authorize('view', $project);

        $project->setRelation('organization', $organization);

        $project->loadCount([
            'sources' => fn ($query) => $query->notArchived(),
            'destinations' => fn ($query) => $query->notArchived(),
            'broadcasts' => fn ($query) => $query->notArchived(),
        ]);

        $sources = $project->sources()->notArchived()->oldest('id')->get();
        $destinations = $project->destinations()->notArchived()->oldest('id')->get();
        $broadcasts = $project->broadcasts()
            ->notArchived()
            ->with(['source', 'destination'])
            ->oldest('id')
            ->get();

        $recentRuns = $project->runs()
            ->latest('started_at')
            ->latest('id')
            ->limit(5)
            ->get();

        $lastLiveRun = $project->runs()
            ->where('is_dry', false)
            ->latest('started_at')
            ->latest('id')
            ->first();

        $autoPausedBroadcasts = $broadcasts
            ->filter(fn ($broadcast) => $broadcast->failure_pause_reason !== null)
            ->values();

        return view('projects.show', [
            'project' => $project,
            'sources' => $sources,
            'destinations' => $destinations,
            'broadcasts' => $broadcasts,
            'recentRuns' => $recentRuns,
            'lastRun' => $recentRuns->first(),
            'lastLiveRun' => $lastLiveRun,
            'health' => $health->resolve($project, $lastLiveRun, $autoPausedBroadcasts),
            'autoPausedBroadcasts' => $autoPausedBroadcasts,
            'isEmpty' => $project->sources_count === 0,
            'scheduleForm' => $scheduleForm->toArray($project),
        ]);
    }

    public function edit(Organization $organization, Project $project): View
    {
        Gate::authorize('update', $project);

        return view('projects.edit', [
            'project' => $project,
        ]);
    }

    public function update(UpdateProjectRequest $request, Organization $organization, Project $project): RedirectResponse
    {
        $project->update([
            'name' => $request->string('name')->toString(),
            'description' => $request->nullableDescription(),
        ]);

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'The project has been updated.');
    }
}
