<?php

namespace App\Support;

use App\Models\Project;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Runs one already-claimed project and releases the claim the same way
 * DispatchDueProjects did inline: advance next_run_at, or leave the project
 * due when the run was incomplete or a delivery retry arrived mid-claim.
 *
 * A visual broadcast only reads the sources that are due, then advances just
 * those; its next run is the earliest of its sources' next runs. A run with
 * no source due (Run now, a retry) reads every route, as it always did.
 */
class RunClaimedProject
{
    public function __construct(
        private ProjectRunner $runner,
        private SourceSchedules $schedules,
    ) {}

    public function handle(Project $project, CarbonInterface $claimedAt, ?CarbonInterface $deadline = null): void
    {
        $project->refresh();
        $project->loadMissing('organization');

        // Suspended after claim (or a race with the dispatcher): release cleanly
        // and schedule from now so a long suspension does not burst on reinstate.
        if ($project->organization->isDisabled()) {
            $project->claimed_at = null;
            $project->refreshNextRunAt();
            $project->save();

            return;
        }

        $completed = true;
        $failed = false;
        $dueSourceIds = $project->isVisual() ? $this->schedules->dueSourceIds($project, $claimedAt) : [];
        $onlySources = $dueSourceIds === [] ? null : $dueSourceIds;

        try {
            $completed = $this->runner->run($project, $deadline, $onlySources);
        } catch (Throwable $e) {
            report($e);
            $failed = true;
        }

        $project->claimed_at = null;

        if (! $completed) {
            $project->next_run_at = now();
        } else {
            if ($project->isVisual()) {
                $project->next_run_at = $this->schedules->refresh($project, now(), $onlySources ?? true);
            } else {
                $project->refreshNextRunAt();
            }

            if (! $failed && $project->hasRetriesRequestedSince($claimedAt)) {
                $project->next_run_at = now();
            }
        }

        $project->save();
    }
}
