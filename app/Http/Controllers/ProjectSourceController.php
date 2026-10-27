<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSourceRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Source;
use App\Support\Pipeline\PipelineEditorPayload;
use App\Support\SourceFormData;
use App\Support\SourceProviderRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProjectSourceController extends Controller
{
    public function __construct(
        private SourceProviderRegistry $providers,
        private SourceFormData $form,
        private PipelineEditorPayload $payload,
    ) {}

    public function create(Request $request, Organization $organization, Project $project): View|RedirectResponse
    {
        Gate::authorize('create', [Source::class, $project]);

        if (! $project->isVisual() && $project->sources()->notArchived()->exists()) {
            return redirect()
                ->route('broadcasts.show', $project)
                ->with('error', 'This broadcast already has a source. Manage it from Overview.');
        }

        $type = $this->chosenType($request);

        abort_if($type === null, 404);

        return $this->form->view($project, $type);
    }

    public function store(StoreSourceRequest $request, Organization $organization, Project $project): RedirectResponse|JsonResponse
    {
        if (! $project->isVisual() && $project->sources()->notArchived()->exists()) {
            return redirect()
                ->route('broadcasts.show', $project)
                ->with('error', 'This broadcast already has a source. Manage it from Overview.');
        }

        $source = $project->sources()->make([
            'name' => $request->name(),
            'type' => $request->type(),
            'config' => $request->configuration(),
        ]);

        // Visual broadcasts run each source on its own schedule.
        if ($project->isVisual() && $request->hasSchedule()) {
            $source->schedule = $request->sourceSchedule();
        }

        $source->save();

        $project->ensureDefaultBroadcast();

        // The pipeline editor adds sources from its inspector and keeps the canvas.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'The source has been added.',
                'source' => $this->payload->source($source),
            ], 201);
        }

        return redirect()
            ->route($project->isVisual() ? 'pipelines.edit' : 'broadcasts.show', $project)
            ->with('success', 'The source has been added.');
    }

    /**
     * Which provider's fields the form is showing. A rejected submission comes
     * back with the type it was filled in for.
     */
    private function chosenType(Request $request): ?string
    {
        $requested = $request->old('type', $request->string('type')->toString());

        if (is_string($requested) && $this->providers->has($requested)) {
            return $requested;
        }

        return $this->providers->defaultType();
    }
}
