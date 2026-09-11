<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class PausedProjectController extends Controller
{
    public function store(Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('changeStatus', $project);

        $project->pause();

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'The project has been paused.');
    }

    public function destroy(Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('changeStatus', $project);

        $project->resume();

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'The project has been resumed.');
    }
}
