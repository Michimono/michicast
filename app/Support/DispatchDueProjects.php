<?php

namespace App\Support;

use App\Jobs\RunProject;
use App\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class DispatchDueProjects
{
    public function __construct(private RunClaimedProject $runner) {}

    /**
     * Claim due active projects until the time budget is spent.
     *
     * When runs_on_queue is on, each claim is handed to a RunProject job and
     * the job releases the claim. Otherwise the claim is run inline here.
     *
     * A project stays due when the budget ran out, or when a delivery retry
     * was requested after this tick claimed it and is still waiting.
     */
    public function handle(): void
    {
        $startedAt = now();
        $deadline = $startedAt->copy()->addSeconds(max(0, (int) config('michicast.dispatch.time_budget')));
        $staleBefore = $startedAt->copy()->subSeconds(max(0, (int) config('michicast.dispatch.stale_claim_after')));
        $onQueue = (bool) config('michicast.runs_on_queue');

        while (now()->lt($deadline)) {
            $project = $this->nextDue($startedAt, $staleBefore);

            if ($project === null) {
                break;
            }

            if (! $project->claimDueRun($startedAt, $staleBefore)) {
                continue;
            }

            if ($onQueue) {
                RunProject::dispatch((int) $project->getKey(), $startedAt->toIso8601String());

                continue;
            }

            $this->runner->handle($project, $startedAt, $deadline);
        }
    }

    private function nextDue(CarbonInterface $now, CarbonInterface $staleBefore): ?Project
    {
        return Project::query()
            ->dispatchable()
            ->with('organization')
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->where(function (Builder $query) use ($staleBefore): void {
                $query->whereNull('claimed_at')
                    ->orWhere('claimed_at', '<=', $staleBefore);
            })
            ->orderBy('next_run_at')
            ->orderBy('id')
            ->first();
    }
}
