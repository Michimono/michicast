<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Source;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class EnabledSourceController extends Controller
{
    public function store(Organization $organization, Source $source): RedirectResponse
    {
        Gate::authorize('changeStatus', $source);

        $source->enable();

        return back()->with('success', 'The source has been enabled.');
    }

    public function destroy(Organization $organization, Source $source): RedirectResponse
    {
        Gate::authorize('changeStatus', $source);

        $source->disable();

        return back()->with('success', 'The source has been disabled.');
    }
}
