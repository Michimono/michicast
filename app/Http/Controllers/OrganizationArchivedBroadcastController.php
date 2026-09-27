<?php

namespace App\Http\Controllers;

use App\Models\Organization;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class OrganizationArchivedBroadcastController extends Controller
{
    /**
     * Archived broadcasts for one organization, reachable only from the org rail.
     */
    public function index(Organization $organization): View
    {
        Gate::authorize('viewAny', [Project::class, $organization]);

        $projects = $organization->projects()
            ->archived()
            ->with('latestRun')
            ->oldest('id')
            ->get();

        $projects->each(fn ($project) => $project->setRelation('organization', $organization));

        return view('organization-archived-broadcasts.index', [
            'organization' => $organization,
            'projects' => $projects,
        ]);
    }
}
