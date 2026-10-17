<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDestinationRequest;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Support\DestinationProviderRegistry;
use App\Support\DestinationTypePresentation;
use App\Support\Pipeline\PipelineEditorPayload;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProjectDestinationController extends Controller
{
    public function __construct(
        private DestinationProviderRegistry $providers,
        private PipelineEditorPayload $payload,
    ) {}

    public function create(Request $request, Organization $organization, Project $project): View|RedirectResponse
    {
        Gate::authorize('create', [Destination::class, $project]);

        if (! $project->isVisual() && $project->destinations()->notArchived()->exists()) {
            return redirect()
                ->route('broadcasts.show', $project)
                ->with('error', 'This broadcast already has a destination. Manage it from Overview.');
        }

        $type = $this->chosenType($request);

        abort_if($type === null, 404);

        $types = $this->providers->options();

        return view('project-destinations.create', [
            'project' => $project,
            'type' => $type,
            // Every offered type's tile and fields, so the form can switch types without a reload.
            'typeGroups' => collect($this->providers->selectableTypes())
                ->mapWithKeys(fn (string $option): array => [$option => [
                    'label' => $types[$option] ?? $option,
                    'presentation' => DestinationTypePresentation::for($option, $types[$option] ?? $option),
                    'fields' => $this->providers->configurationFields($option),
                    'credentialFields' => $this->providers->credentialFields($option),
                ]])
                ->all(),
        ]);
    }

    public function store(StoreDestinationRequest $request, Organization $organization, Project $project): RedirectResponse|JsonResponse
    {
        if (! $project->isVisual() && $project->destinations()->notArchived()->exists()) {
            return redirect()
                ->route('broadcasts.show', $project)
                ->with('error', 'This broadcast already has a destination. Manage it from Overview.');
        }

        $destination = $project->destinations()->create([
            'name' => $request->name(),
            'type' => $request->type(),
            'config' => $request->configuration(),
            'credentials' => $request->credentials(),
        ]);

        $project->ensureDefaultBroadcast();

        // The pipeline editor adds destinations from its inspector; secrets stay server-side.
        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'The destination has been added.',
                'destination' => $this->payload->destination($destination),
            ], 201);
        }

        return redirect()
            ->route($project->isVisual() ? 'pipelines.edit' : 'broadcasts.show', $project)
            ->with('success', 'The destination has been added.');
    }

    private function chosenType(Request $request): ?string
    {
        $requested = $request->old('type', $request->string('type')->toString());

        if (is_string($requested) && $this->providers->isSelectable($requested)) {
            return $requested;
        }

        return $this->providers->defaultType();
    }
}
