<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class PausedProjectController extends Controller
{
    public function store(Request $request, Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('changeStatus', $project);

        $project->pause();

        return redirect()
            ->to($this->afterMutation($request, $organization, $project))
            ->with('success', 'The broadcast has been paused.');
    }

    public function destroy(Request $request, Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('changeStatus', $project);

        $project->resume();

        return redirect()
            ->to($this->afterMutation($request, $organization, $project))
            ->with('success', 'The broadcast has been resumed.');
    }

    private function afterMutation(Request $request, Organization $organization, Project $project): string
    {
        if ($request->string('return')->toString() === 'organization') {
            return route('organizations.show', $organization);
        }

        return route('broadcasts.show', $project);
    }
}
