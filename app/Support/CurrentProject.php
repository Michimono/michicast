<?php

namespace App\Support;

use App\Models\Broadcast;
use App\Models\Destination;
use App\Models\Project;
use App\Models\Source;

/**
 * Resolves the project in scope for the current request, when any.
 */
class CurrentProject
{
    public function project(): ?Project
    {
        $project = request()->route('project');

        if ($project instanceof Project) {
            return $project;
        }

        foreach (['broadcast', 'source', 'destination'] as $parameter) {
            $model = request()->route($parameter);

            if ($model instanceof Broadcast || $model instanceof Source || $model instanceof Destination) {
                $model->loadMissing('project');

                return $model->project;
            }
        }

        return null;
    }
}
