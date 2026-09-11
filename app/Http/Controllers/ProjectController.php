<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Models\Project;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ProjectController extends Controller
{
    public function __construct(private CurrentOrganization $current) {}

    public function index(): View
    {
        $organization = $this->current->organization();

        Gate::authorize('viewAny', [Project::class, $organization]);

        return view('projects.index', [
            'organization' => $organization,
            'projects' => $organization->projects()->oldest('id')->get(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('create', [Project::class, $this->current->organization()]);

        return view('projects.create');
    }

    public function store(StoreProjectRequest $request): RedirectResponse
    {
        $organization = $this->current->organization();
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

    public function show(Project $project): View
    {
        $this->ensureCurrent($project);

        Gate::authorize('view', $project);

        return view('projects.show', [
            'project' => $project,
        ]);
    }

    public function edit(Project $project): View
    {
        $this->ensureCurrent($project);

        Gate::authorize('update', $project);

        return view('projects.edit', [
            'project' => $project,
        ]);
    }

    public function update(UpdateProjectRequest $request, Project $project): RedirectResponse
    {
        $project->update([
            'name' => $request->string('name')->toString(),
            'description' => $request->nullableDescription(),
        ]);

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'The project has been updated.');
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
