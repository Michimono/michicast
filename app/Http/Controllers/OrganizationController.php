<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Middleware\SetCurrentOrganization;
use App\Http\Requests\StoreOrganizationRequest;
use App\Models\Organization;
use App\Models\OrganizationMember;
use App\Support\OrganizationSubmissions;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Throwable;

class OrganizationController extends Controller
{
    /**
     * Every organization the signed-in user belongs to, which is also how
     * they move between them. Someone with no membership yet (suspended
     * organizations still count) is sent to create their first one.
     */
    public function index(Request $request): View|RedirectResponse
    {
        if ($request->user()->memberships()->doesntExist()) {
            return redirect()->route('organizations.create');
        }

        return view('organizations.index', [
            'memberships' => $request->user()
                ->memberships()
                ->with('organization')
                ->get()
                ->sortBy(fn (OrganizationMember $membership): string => $membership->organization->name)
                ->values(),
        ]);
    }

    /**
     * The same form creates a first organization and any later one; someone
     * without a membership yet also gets a short introduction to Michicast.
     */
    public function create(Request $request): View
    {
        return view('organizations.create', [
            'firstOrganization' => $request->user()->memberships()->doesntExist(),
            'submission' => (string) Str::uuid(),
            'timezone' => $request->user()->timezone,
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    /**
     * A repeated submission of one rendered form (double click, back and
     * resubmit) lands on the organization it already created.
     */
    public function store(StoreOrganizationRequest $request, OrganizationSubmissions $submissions): RedirectResponse
    {
        $token = $request->submissionToken();

        try {
            $organization = $token === null
                ? $this->createOrganization($request)
                : $submissions->once($request->user(), $token, fn (): Organization => $this->createOrganization($request));
        } catch (Throwable $exception) {
            report($exception);

            return back()
                ->withInput()
                ->withErrors(['organization' => 'We couldn’t create your organization. Please try again.']);
        }

        $request->session()->put(SetCurrentOrganization::SESSION_KEY, $organization->getKey());

        return redirect()
            ->route('organizations.show', $organization)
            ->with('success', 'You’re all set — your organization has been created.');
    }

    /**
     * The organization's workspace home: a quiet grid of broadcast cards.
     */
    public function show(Organization $organization): View
    {
        Gate::authorize('view', $organization);

        $projects = $organization->projects()
            ->notArchived()
            ->with('latestRun')
            ->oldest('id')
            ->get();

        $projects->each(fn ($project) => $project->setRelation('organization', $organization));

        return view('organizations.show', [
            'organization' => $organization,
            'projects' => $projects,
        ]);
    }

    private function createOrganization(StoreOrganizationRequest $request): Organization
    {
        $name = $request->string('name')->toString();

        return DB::transaction(function () use ($request, $name): Organization {
            $organization = Organization::create([
                'name' => $name,
                'slug' => Organization::uniqueSlugFrom($name),
                'timezone' => $request->string('timezone')->toString(),
                'plan' => config('michicast.quotas.default_plan'),
            ]);

            $creator = $request->user();

            // New workspaces wait for a platform admin unless approval is off
            // or the creator is an admin themselves.
            if (! config('michicast.organizations.require_approval') || $creator->isPlatformAdmin()) {
                $organization->forceFill([
                    'approved_at' => now(),
                    'approved_by' => $creator->isPlatformAdmin() ? $creator->getKey() : null,
                ])->save();
            }

            $organization->members()->create([
                'user_id' => $creator->getKey(),
                'role' => Role::Owner,
            ]);

            return $organization;
        });
    }
}
