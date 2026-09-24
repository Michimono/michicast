<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ArchivedProjectController extends Controller
{
    public function store(Request $request, Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('changeStatus', $project);

        $project->archive();

        return redirect()
            ->to($this->afterMutation($request, $organization, $project))
            ->with('success', 'The project has been archived.');
    }

    public function destroy(Request $request, Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('changeStatus', $project);

        $project->restore();

        return redirect()
            ->to($this->afterMutation($request, $organization, $project))
            ->with('success', 'The project has been restored.');
    }

    private function afterMutation(Request $request, Organization $organization, Project $project): string
    {
        if ($request->string('return')->toString() === 'organization') {
            return route('organizations.show', $organization);
        }

        return route('projects.show', $project);
    }
}
