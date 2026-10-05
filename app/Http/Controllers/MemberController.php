<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Middleware\SetCurrentOrganization;
use App\Http\Requests\DestroyMemberRequest;
use App\Models\Organization;
use App\Models\OrganizationMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class MemberController extends Controller
{
    public function index(Organization $organization): View
    {
        Gate::authorize('view', $organization);

        return view('members.index', [
            'organization' => $organization,
            'members' => $organization->members()->with('user')->oldest('id')->get(),
            'invitations' => $organization->invitations()->pending()->oldest('id')->get(),
            'roles' => Role::options(),
        ]);
    }

    public function destroy(DestroyMemberRequest $request, Organization $organization, OrganizationMember $member): RedirectResponse
    {
        if ($member->isLastOwner()) {
            return redirect()
                ->route('members.index')
                ->with('error', 'This is the last owner. Give another member the owner role first.');
        }

        $leaving = $request->user()->is($member->user);

        $member->delete();

        if ($leaving) {
            $request->session()->forget(SetCurrentOrganization::SESSION_KEY);

            return redirect()
                ->route('dashboard')
                ->with('success', 'You have left the organization.');
        }

        return redirect()
            ->route('members.index')
            ->with('success', 'The member has been removed.');
    }
}
