<?php

namespace App\Http\Controllers;

use App\Enums\RunStatus;
use App\Models\Organization;
use App\Models\Project;
use App\Support\ExecutingProjectRunner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Throwable;

class BroadcastDryRunController extends Controller
{
    public function store(
        Organization $organization,
        Project $project,
        ExecutingProjectRunner $runner,
    ): RedirectResponse {
        Gate::authorize('dryRun', $project);

        try {
            $run = $runner->dryRun($project);
        } catch (Throwable $e) {
            report($e);

            return redirect()
                ->route('broadcasts.edit', $project)
                ->with('error', 'The dry run could not finish: '.$e->getMessage());
        }

        if ($run->status === RunStatus::QuotaExceeded) {
            $detail = $run->broadcastRuns()
                ->where('status', RunStatus::QuotaExceeded)
                ->value('error_detail');

            return redirect()
                ->route('broadcasts.edit', $project)
                ->with('error', $detail ?: $run->status->label())
                ->with('last_run_id', $run->getKey());
        }

        return redirect()
            ->route('broadcasts.edit', $project)
            ->with('success', 'Dry run finished. Nothing was published.')
            ->with('last_run_id', $run->getKey());
    }
}
