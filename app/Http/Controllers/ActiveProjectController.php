<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ActiveProjectController extends Controller
{
    public function __construct(private CurrentOrganization $current) {}

    public function store(Project $project): RedirectResponse
    {
        $this->ensureCurrent($project);

        Gate::authorize('changeStatus', $project);

        $project->activate();

        return redirect()
            ->route('projects.show', $project)
            ->with('success', 'The project has been activated.');
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
