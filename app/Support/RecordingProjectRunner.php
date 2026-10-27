<?php

namespace App\Support;

use App\Models\Project;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;

class RecordingProjectRunner implements ProjectRunner
{
    /**
     * Placeholder kept for tests that want a no-op runner. Production binds
     * ExecutingProjectRunner instead.
     *
     * @param  list<int>|null  $sourceIds
     */
    public function run(Project $project, ?CarbonInterface $deadline = null, ?array $sourceIds = null): bool
    {
        Log::info('Project dispatched.', [
            'project_id' => $project->getKey(),
        ]);

        return true;
    }
}
