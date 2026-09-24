<?php

namespace App\Support;

use App\Enums\ProjectHealth;
use App\Enums\RunStatus;
use App\Models\Broadcast;
use App\Models\Project;
use App\Models\ProjectRun;
use Illuminate\Support\Collection;

/**
 * Derives overview health from recent live runs and auto-paused broadcasts.
 * Dry runs do not count as evidence the project has run in production.
 */
class ProjectHealthResolver
{
    /**
     * @param  Collection<int, Broadcast>  $autoPausedBroadcasts
     */
    public function resolve(Project $project, ?ProjectRun $lastLiveRun, Collection $autoPausedBroadcasts): ProjectHealth
    {
        if ($autoPausedBroadcasts->isNotEmpty()) {
            return ProjectHealth::AttentionNeeded;
        }

        if ($lastLiveRun === null) {
            return ProjectHealth::NeverRun;
        }

        return match ($lastLiveRun->status) {
            RunStatus::Completed => ProjectHealth::Healthy,
            RunStatus::Failed, RunStatus::Incomplete, RunStatus::Running => ProjectHealth::AttentionNeeded,
        };
    }
}
