<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProjectScheduleRequest;
use App\Models\Organization;
use App\Models\Project;
use App\Support\ProjectScheduleForm;
use Illuminate\View\View;

class ProjectSchedulePreviewController extends Controller
{
    public function store(
        UpdateProjectScheduleRequest $request,
        Organization $organization,
        Project $project,
        ProjectScheduleForm $form,
    ): View {
        return view('project-schedules.edit', $form->toArray($project, $request));
    }
}
