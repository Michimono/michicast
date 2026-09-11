<?php

namespace App\Support;

use App\Models\Project;
use Illuminate\Support\Facades\Log;

class RecordingProjectRunner implements ProjectRunner
{
    /**
     * Placeholder until the execution engine runs real work. M5 replaces this.
     */
    public function run(Project $project): void
    {
        Log::info('Project dispatched.', [
            'project_id' => $project->getKey(),
        ]);
    }
}
