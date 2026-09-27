<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProjectScheduleRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Support\ProjectScheduleForm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProjectScheduleController extends Controller
{
    public function edit(Organization $organization, Project $project, ProjectScheduleForm $form): View
    {
        Gate::authorize('update', $project);

        return view('project-schedules.edit', $form->toArray($project));
    }

    public function update(UpdateProjectScheduleRequest $request, Organization $organization, Project $project): RedirectResponse
    {
        $project->applySchedule($request->schedule(), $request->timezoneOverride());

        return redirect()
            ->route('broadcasts.show', $project)
            ->with('success', 'The schedule has been saved.');
    }
}
