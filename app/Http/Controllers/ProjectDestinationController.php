<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDestinationRequest;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Support\DestinationProviderRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProjectDestinationController extends Controller
{
    public function __construct(private DestinationProviderRegistry $providers) {}

    public function create(Request $request, Organization $organization, Project $project): View|RedirectResponse
    {
        Gate::authorize('create', [Destination::class, $project]);

        if ($project->destinations()->notArchived()->exists()) {
            return redirect()
                ->route('broadcasts.show', $project)
                ->with('error', 'This broadcast already has a destination. Manage it from Overview.');
        }

        $type = $this->chosenType($request);

        abort_if($type === null, 404);

        return view('project-destinations.create', [
            'project' => $project,
            'types' => $this->providers->options(),
            'type' => $type,
            'fields' => $this->providers->configurationFields($type),
            'credentialFields' => $this->providers->credentialFields($type),
        ]);
    }

    public function store(StoreDestinationRequest $request, Organization $organization, Project $project): RedirectResponse
    {
        if ($project->destinations()->notArchived()->exists()) {
            return redirect()
                ->route('broadcasts.show', $project)
                ->with('error', 'This broadcast already has a destination. Manage it from Overview.');
        }

        $project->destinations()->create([
            'name' => $request->name(),
            'type' => $request->type(),
            'config' => $request->configuration(),
            'credentials' => $request->credentials(),
        ]);

        $project->ensureDefaultBroadcast();

        return redirect()
            ->route('broadcasts.show', $project)
            ->with('success', 'The destination has been added.');
    }

    private function chosenType(Request $request): ?string
    {
        $requested = $request->old('type', $request->string('type')->toString());

        if (is_string($requested) && $this->providers->has($requested)) {
            return $requested;
        }

        return $this->providers->defaultType();
    }
}
