<?php

namespace App\Http\Controllers;

use App\Models\Destination;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class EnabledDestinationController extends Controller
{
    public function store(Organization $organization, Destination $destination): RedirectResponse
    {
        Gate::authorize('changeStatus', $destination);

        $destination->enable();

        return back()->with('success', 'The destination has been enabled.');
    }

    public function destroy(Organization $organization, Destination $destination): RedirectResponse
    {
        Gate::authorize('changeStatus', $destination);

        $destination->disable();

        return back()->with('success', 'The destination has been disabled.');
    }
}
