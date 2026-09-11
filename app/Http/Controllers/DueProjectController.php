<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class DueProjectController extends Controller
{
    public function store(Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('markDue', $project);

        $project->markDue();

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'The run is queued for the next scheduler tick, within about a minute.');
    }
}
