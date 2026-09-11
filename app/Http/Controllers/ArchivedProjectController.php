<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ArchivedProjectController extends Controller
{
    public function store(Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('changeStatus', $project);

        $project->archive();

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'The project has been archived.');
    }

    public function destroy(Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('changeStatus', $project);

        $project->restore();

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'The project has been restored.');
    }
}
