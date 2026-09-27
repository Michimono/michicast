<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProjectScheduleRequest;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ProjectScheduleController extends Controller
{
    public function edit(Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('update', $project);

        return redirect()->route('broadcasts.edit', $project);
    }

    public function update(UpdateProjectScheduleRequest $request, Organization $organization, Project $project): RedirectResponse
    {
        $project->applySchedule($request->schedule(), $request->timezoneOverride());

        return redirect()
            ->route('broadcasts.show', $project)
            ->with('success', 'The schedule has been saved.');
    }
}
