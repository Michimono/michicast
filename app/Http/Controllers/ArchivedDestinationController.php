<?php

namespace App\Http\Controllers;

use App\Models\Destination;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ArchivedDestinationController extends Controller
{
    public function store(Organization $organization, Destination $destination): RedirectResponse
    {
        Gate::authorize('changeStatus', $destination);

        $destination->archive();

        return redirect()
            ->route('broadcast-destinations.index', $destination->project)
            ->with('success', 'The destination has been archived.');
    }

    public function destroy(Organization $organization, Destination $destination): RedirectResponse
    {
        Gate::authorize('changeStatus', $destination);

        $destination->restore();

        return redirect()
            ->route('broadcast-destinations.index', $destination->project)
            ->with('success', 'The destination has been restored.');
    }
}
