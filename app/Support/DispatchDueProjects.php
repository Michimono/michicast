<?php

namespace App\Support;

use App\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

class DispatchDueProjects
{
    public function __construct(private ProjectRunner $runner) {}

    /**
     * Claim and run due active projects until the time budget is spent.
     *
     * A project stays due when the budget ran out, or when a delivery retry
     * was requested after this tick claimed it and is still waiting.
     */
    public function handle(): void
    {
        $startedAt = now();
        $deadline = $startedAt->copy()->addSeconds(max(0, (int) config('michicast.dispatch.time_budget')));
        $staleBefore = $startedAt->copy()->subSeconds(max(0, (int) config('michicast.dispatch.stale_claim_after')));

        while (now()->lt($deadline)) {
            $project = $this->nextDue($startedAt, $staleBefore);

            if ($project === null) {
                break;
            }

            if (! $project->claimDueRun($startedAt, $staleBefore)) {
                continue;
            }

            $project->refresh();
            $project->loadMissing('organization');

            $completed = true;
            $failed = false;

            try {
                $completed = $this->runner->run($project, $deadline);
            } catch (Throwable $e) {
                report($e);
                $failed = true;
            }

            $project->claimed_at = null;

            if (! $completed || (! $failed && $project->hasRetriesRequestedSince($startedAt))) {
                $project->next_run_at = now();
            } else {
                $project->refreshNextRunAt();
            }

            $project->save();
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
