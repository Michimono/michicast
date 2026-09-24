<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Project;
use App\Support\ProjectRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Throwable;

class ImmediateProjectRunController extends Controller
{
    public function store(
        Organization $organization,
        Project $project,
        ProjectRunner $runner,
    ): RedirectResponse {
        Gate::authorize('markDue', $project);

        if (! $project->canMarkDue()) {
            return redirect()
                ->route('projects.show', $project)
                ->with('error', 'Only an active project can run now.');
        }

        try {
            $runner->run($project);
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->route('projects.show', $project)
                ->with('error', 'The run could not finish: '.$e->getMessage());
        }

        $run = $project->runs()->where('is_dry', false)->latest('id')->first();

        return redirect()
            ->route('projects.show', $project)
            ->with('success', $run === null
                ? 'The run finished.'
                : 'Run finished: '.$run->status->label().'.')
            ->with('last_run_id', $run?->getKey());
    }
}
