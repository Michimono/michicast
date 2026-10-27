<?php

namespace App\Support;

use App\Models\Project;
use Carbon\CarbonInterface;

interface ProjectRunner
{
    /**
     * Execute one claimed project. Returns false when the time budget ran out
     * before every broadcast finished, so the dispatcher can leave the project
     * due for the next tick. Throws are reported by the dispatcher; the next
     * run still advances in that case.
     *
     * $sourceIds limits the run to routes reading those sources (a visual
     * broadcast's due sources); null runs every route.
     *
     * @param  list<int>|null  $sourceIds
     */
    public function run(Project $project, ?CarbonInterface $deadline = null, ?array $sourceIds = null): bool;
}
