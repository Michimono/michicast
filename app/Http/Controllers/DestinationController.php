<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateDestinationRequest;
use App\Models\Destination;
use App\Models\Organization;
use App\Support\DestinationProviderRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DestinationController extends Controller
{
    public function __construct(private DestinationProviderRegistry $providers) {}

    public function edit(Organization $organization, Destination $destination): View
    {
        Gate::authorize('update', $destination);

        $known = $this->providers->has($destination->type);

        return view('destinations.edit', [
            'destination' => $destination,
            'known' => $known,
            'typeLabel' => $known ? $this->providers->get($destination->type)->label() : $destination->type,
            'fields' => $known ? $this->providers->configurationFields($destination->type) : [],
            'credentialFields' => $known ? $this->providers->credentialFields($destination->type) : [],
        ]);
    }

    public function update(UpdateDestinationRequest $request, Organization $organization, Destination $destination): RedirectResponse
    {
        $destination->update([
            'name' => $request->name(),
            'config' => $request->configuration(),
            'credentials' => $request->credentials(),
        ]);

        return redirect()
            ->route('destinations.edit', $destination)
            ->with('success', 'The destination has been updated.');
    }
}
