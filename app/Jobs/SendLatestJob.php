<?php

namespace App\Jobs;

use App\Models\Project;
use App\Support\SendLatest;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendLatestJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Seconds the worker may spend on this job. Must stay below
     * DISPATCH_STALE_CLAIM_AFTER so overlapping RunProject reclaim stays safe.
     */
    public int $timeout = 90;

    public int $tries = 1;

    public int $uniqueFor = 120;

    public function __construct(public int $projectId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->projectId;
    }

    public function handle(SendLatest $sendLatest): void
    {
        $project = Project::query()->find($this->projectId);

        if ($project === null || ! $project->canMarkDue()) {
            return;
        }

        $sendLatest->handle($project);
    }
}
