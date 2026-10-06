<?php

namespace App\Support;

use App\Models\Project;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Runs one already-claimed project and releases the claim the same way
 * DispatchDueProjects did inline: advance next_run_at, or leave the project
 * due when the run was incomplete or a delivery retry arrived mid-claim.
 */
class RunClaimedProject
{
    public function __construct(private ProjectRunner $runner) {}

    public function handle(Project $project, CarbonInterface $claimedAt, ?CarbonInterface $deadline = null): void
    {
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

        if (! $completed || (! $failed && $project->hasRetriesRequestedSince($claimedAt))) {
            $project->next_run_at = now();
        } else {
            $project->refreshNextRunAt();
        }

        $project->save();
    }
}
