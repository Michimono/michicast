<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProjectScheduleRequest;
use App\Models\Project;
use App\Support\CurrentOrganization;
use App\Support\ProjectScheduleForm;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProjectScheduleController extends Controller
{
    public function __construct(private CurrentOrganization $current) {}

    public function edit(Project $project, ProjectScheduleForm $form): View
    {
        $this->ensureCurrent($project);

        Gate::authorize('update', $project);

        return view('project-schedules.edit', $form->toArray($project));
    }

    public function update(UpdateProjectScheduleRequest $request, Project $project): RedirectResponse
    {
        $project->applySchedule($request->schedule(), $request->timezoneOverride());

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'The schedule has been saved.');
    }

    /**
     * Hide another organization's project rather than refusing it.
     */
    private function ensureCurrent(Project $project): void
    {
        if ($project->organization()->isNot($this->current->organization())) {
            abort(404);
        }
    }
}
