<?php

namespace App\Jobs;

use App\Models\Project;
use App\Support\RunClaimedProject;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunProject implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    /**
     * Seconds the worker may spend on this job. Must stay below
     * DISPATCH_STALE_CLAIM_AFTER so a killed worker's claim becomes reclaimable.
     */
    public int $timeout = 90;

    /**
     * Do not retry: a dead job leaves claimed_at set and the stale-claim
     * window re-dispatches it.
     */
    public int $tries = 1;

    /**
     * Keep the unique lock roughly as long as the stale-claim window.
     */
    public int $uniqueFor = 120;

    /**
     * @param  list<int>|null  $sourceIds  Only run these sources (Run now on a source, #241); null runs what is due.
     */
    public function __construct(
        public int $projectId,
        public string $claimedAt,
        public ?array $sourceIds = null,
    ) {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->projectId;
    }

    public function handle(RunClaimedProject $runner): void
    {
        $project = Project::query()->with('organization')->find($this->projectId);

        if ($project === null || $project->claimed_at === null) {
            return;
        }

        $runner->handle($project, CarbonImmutable::parse($this->claimedAt), null, $this->sourceIds);
    }
}
