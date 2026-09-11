<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function index(Organization $organization): View
    {
        Gate::authorize('viewAny', [Project::class, $organization]);

        return view('projects.index', [
            'organization' => $organization,
            'projects' => $organization->projects()->notArchived()->oldest('id')->get(),
        ]);
    }

    public function create(Organization $organization): View
    {
        Gate::authorize('create', [Project::class, $organization]);

        return view('projects.create');
    }

    public function store(StoreProjectRequest $request, Organization $organization): RedirectResponse
    {
        $name = $request->string('name')->toString();

        $project = $organization->projects()->create([
            'name' => $name,
            'slug' => Project::uniqueSlugFrom($name, $organization),
            'description' => $request->nullableDescription(),
        ]);

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'The project has been created.');
    }

    public function show(Organization $organization, Project $project): View
    {
        Gate::authorize('view', $project);

        return view('projects.show', [
            'project' => $project,
        ]);
    }

    public function edit(Organization $organization, Project $project): View
    {
        Gate::authorize('update', $project);

        return view('projects.edit', [
            'project' => $project,
        ]);
    }

    public function update(UpdateProjectRequest $request, Organization $organization, Project $project): RedirectResponse
    {
        $project->update([
            'name' => $request->string('name')->toString(),
            'description' => $request->nullableDescription(),
        ]);

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'The project has been updated.');
    }
}
