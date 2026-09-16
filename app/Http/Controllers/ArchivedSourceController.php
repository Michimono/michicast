<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Source;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ArchivedSourceController extends Controller
{
    public function store(Organization $organization, Source $source): RedirectResponse
    {
        Gate::authorize('changeStatus', $source);

        $source->archive();

        return redirect()
            ->route('project-sources.index', $source->project)
            ->with('success', 'The source has been archived.');
    }

    public function destroy(Organization $organization, Source $source): RedirectResponse
    {
        Gate::authorize('changeStatus', $source);

        $source->restore();

        return redirect()
            ->route('project-sources.index', $source->project)
            ->with('success', 'The source has been restored.');
    }
}
