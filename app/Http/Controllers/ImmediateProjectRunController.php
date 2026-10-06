<?php

namespace App\Http\Controllers;

use App\Jobs\RunProject;
use App\Models\Organization;
use App\Models\Project;
use App\Support\ProjectRunner;
use App\Support\SecretRedactor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Throwable;

class ImmediateProjectRunController extends Controller
{
    public function store(
        Organization $organization,
        Project $project,
        ProjectRunner $runner,
        SecretRedactor $secrets,
    ): RedirectResponse {
        Gate::authorize('markDue', $project);

        if (! $project->canMarkDue()) {
            return redirect()
                ->route('broadcasts.show', $project)
                ->with('error', 'Only an active broadcast can run now.');
        }

        if (config('michicast.runs_on_queue')) {
            return $this->queueRun($project);
        }

        try {
            $runner->run($project);
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->route('broadcasts.show', $project)
                ->with('error', 'The run could not finish: '.$secrets->redact($e->getMessage()));
        }

        $run = $project->runs()->where('is_dry', false)->latest('id')->first();

        return redirect()
            ->route('broadcasts.show', $project)
            ->with('success', $run === null
                ? 'The run finished.'
                : 'Run finished: '.$run->status->label().'.')
            ->with('last_run_id', $run?->getKey());
    }

    private function queueRun(Project $project): RedirectResponse
    {
        $project->markDue();
        $project->refresh();

        $claimedAt = now();
        $staleBefore = $claimedAt->copy()->subSeconds(max(0, (int) config('michicast.dispatch.stale_claim_after')));

        if ($project->claimDueRun($claimedAt, $staleBefore)) {
            RunProject::dispatch((int) $project->getKey(), $claimedAt->toIso8601String());
        }

        return redirect()
            ->route('broadcasts.show', $project)
            ->with('success', 'Queued');
    }
}
