<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Support\Pipeline\PipelineEditorPayload;
use App\Support\ProjectHealthResolver;
use App\Support\ProjectScheduleForm;
use App\Support\ScheduleSummary;
use App\Support\SecretRedactor;
use Illuminate\Http\JsonResponse;
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
            'kind' => $request->kind(),
        ]);

        if ($request->hasSchedule()) {
            $project->applySchedule($request->schedule(), $request->timezoneOverride());
        }

        if ($project->isVisual()) {
            return redirect()
                ->route('pipelines.edit', $project)
                ->with('success', 'The broadcast has been created. Draw its pipeline to get started.');
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
        SecretRedactor $secrets,
    ): View|RedirectResponse {
        Gate::authorize('view', $project);

        // The canvas is the overview of a visual broadcast.
        if ($project->isVisual()) {
            return redirect()->route('pipelines.edit', $project);
        }

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
            'lastLiveRun' => $lastLiveRun,
            'health' => $health->resolve($project, $lastLiveRun, $autoPausedBroadcasts),
            'autoPausedBroadcasts' => $autoPausedBroadcasts,
            'scheduleSummary' => $scheduleSummary->describe($project->schedule),
            'mappingEditor' => $mappingEditor,
            'secrets' => $secrets,
        ]);
    }

    public function edit(Organization $organization, Project $project, ProjectScheduleForm $scheduleForm): View
    {
        Gate::authorize('update', $project);

        return view('projects.edit', $scheduleForm->toArray($project));
    }

    public function update(
        UpdateProjectRequest $request,
        Organization $organization,
        Project $project,
        PipelineEditorPayload $payload,
    ): RedirectResponse|JsonResponse {
        $project->update([
            'name' => $request->string('name')->toString(),
            'description' => $request->nullableDescription(),
        ]);

        if ($request->hasSchedule()) {
            $project->applySchedule($request->schedule(), $request->timezoneOverride());
        }

        // The pipeline editor edits name and schedule from its settings panel.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'The broadcast has been updated.',
                'project' => $payload->project($project->refresh()),
            ]);
        }

        return redirect()
            ->route('broadcasts.show', $project)
            ->with('success', 'The broadcast has been updated.');
    }
}
