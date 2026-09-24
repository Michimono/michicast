<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Middleware\SetCurrentOrganization;
use App\Http\Requests\StoreOrganizationRequest;
use App\Models\Organization;
use App\Models\OrganizationMember;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class OrganizationController extends Controller
{
    /**
     * Every organization the signed-in user belongs to, which is also how
     * they move between them.
     */
    public function index(Request $request): View
    {
        return view('organizations.index', [
            'memberships' => $request->user()
                ->memberships()
                ->with('organization')
                ->get()
                ->sortBy(fn (OrganizationMember $membership): string => $membership->organization->name)
                ->values(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('organizations.create', [
            'timezone' => $request->user()->timezone,
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    public function store(StoreOrganizationRequest $request): RedirectResponse
    {
        $name = $request->string('name')->toString();

        $organization = DB::transaction(function () use ($request, $name): Organization {
            $organization = Organization::create([
                'name' => $name,
                'slug' => Organization::uniqueSlugFrom($name),
                'timezone' => $request->string('timezone')->toString(),
            ]);

            $organization->members()->create([
                'user_id' => $request->user()->getKey(),
                'role' => Role::Owner,
            ]);

            return $organization;
        });

        $request->session()->put(SetCurrentOrganization::SESSION_KEY, $organization->getKey());

        return redirect()
            ->route('members.index', $organization)
            ->with('success', 'Your organization has been created.');
    }

    /**
     * The organization's workspace home: a quiet grid of project cards.
     */
    public function show(Organization $organization): View
    {
        Gate::authorize('view', $organization);

        $projects = $organization->projects()
            ->notArchived()
            ->withCount([
                'sources' => fn ($query) => $query->notArchived(),
                'destinations' => fn ($query) => $query->notArchived(),
                'broadcasts' => fn ($query) => $query->notArchived(),
            ])
            ->with('latestRun')
            ->oldest('id')
            ->get();

        $projects->each(fn ($project) => $project->setRelation('organization', $organization));

        return view('organizations.show', [
            'organization' => $organization,
            'projects' => $projects,
        ]);
    }
}
