<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Support\ProjectHealthResolver;
use App\Support\ProjectScheduleForm;
use App\Support\ScheduleSummary;
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

    public function create(Organization $organization, ProjectScheduleForm $scheduleForm): View
    {
        Gate::authorize('create', [Project::class, $organization]);

        return view('projects.create', $scheduleForm->forCreate($organization));
    }

    public function store(StoreProjectRequest $request, Organization $organization): RedirectResponse
    {
        $name = $request->string('name')->toString();

        $project = $organization->projects()->create([
            'name' => $name,
            'slug' => Project::uniqueSlugFrom($name, $organization),
            'description' => $request->nullableDescription(),
        ]);

        if ($request->hasSchedule()) {
            $project->applySchedule($request->schedule(), $request->timezoneOverride());
        }

        return redirect()
            ->route('broadcasts.show', $project)
            ->with('success', 'The broadcast has been created.');
    }

    public function show(
        Organization $organization,
        Project $project,
        ProjectHealthResolver $health,
        ScheduleSummary $scheduleSummary,
    ): View {
        Gate::authorize('view', $project);

        $project->setRelation('organization', $organization);
        $broadcast = $project->ensureDefaultBroadcast();
        $broadcast?->load(['source', 'destination']);

        $source = $broadcast?->source;
        if ($source === null || $source->isArchived()) {
            $source = $project->sources()->notArchived()->oldest('id')->first();
        }

        $destination = $broadcast?->destination;
        if ($destination === null || $destination->isArchived()) {
            $destination = $project->destinations()->notArchived()->oldest('id')->first();
        }

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

        $autoPausedBroadcasts = $project->broadcasts()
            ->notArchived()
            ->whereNotNull('failure_pause_reason')
            ->oldest('id')
            ->get();

        $mappingEditor = ['ready' => false];
        if ($broadcast !== null && $source !== null && $destination !== null
            && ! $source->isArchived() && ! $destination->isArchived()) {
            $mappingEditor = app(BroadcastMappingController::class)->editorPayload($broadcast);
        }

        return view('projects.show', [
            'project' => $project,
            'broadcast' => $broadcast,
            'source' => $source,
            'destination' => $destination,
            'recentRuns' => $recentRuns,
            'lastLiveRun' => $lastLiveRun,
            'health' => $health->resolve($project, $lastLiveRun, $autoPausedBroadcasts),
            'autoPausedBroadcasts' => $autoPausedBroadcasts,
            'pipelineEmpty' => $source === null && $destination === null,
            'scheduleSummary' => $scheduleSummary->describe($project->schedule),
            'mappingEditor' => $mappingEditor,
        ]);
    }

    public function edit(Organization $organization, Project $project, ProjectScheduleForm $scheduleForm): View
    {
        Gate::authorize('update', $project);

        return view('projects.edit', $scheduleForm->toArray($project));
    }

    public function update(UpdateProjectRequest $request, Organization $organization, Project $project): RedirectResponse
    {
        $project->update([
            'name' => $request->string('name')->toString(),
            'description' => $request->nullableDescription(),
        ]);

        if ($request->hasSchedule()) {
            $project->applySchedule($request->schedule(), $request->timezoneOverride());
        }

        return redirect()
            ->route('broadcasts.show', $project)
            ->with('success', 'The broadcast has been updated.');
    }
}
