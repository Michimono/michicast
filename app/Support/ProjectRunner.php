<?php

namespace App\Support;

use App\Models\Project;

interface ProjectRunner
{
    /**
     * Execute one claimed project. The dispatcher advances the next run
     * whether this succeeds or throws.
     */
    public function run(Project $project): void;
}
