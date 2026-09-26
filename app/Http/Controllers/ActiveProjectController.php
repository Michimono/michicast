<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;

class ActiveProjectController extends Controller
{
    public function store(Organization $organization, Project $project): RedirectResponse
    {
        Gate::authorize('changeStatus', $project);

        $project->activate();

        return redirect()
            ->route('broadcasts.show', $project)
            ->with('success', 'The broadcast has been activated.');
    }
}
