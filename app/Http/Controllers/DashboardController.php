<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetCurrentOrganization;
use App\Models\OrganizationMember;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Where signing in, leaving an organization and every other "just take me
 * somewhere sensible" redirect lands. Organization screens live under an
 * organization URL, so this only picks which one and forwards; it is a
 * landing redirect rather than a resource of its own.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $membership = $this->lastWorkedIn($request->user(), $request->session()->get(SetCurrentOrganization::SESSION_KEY));

        if ($membership === null) {
            return redirect()->route('organizations.create');
        }

        return redirect()->route('organizations.show', $membership->organization_id);
    }

    /**
     * The organization the user last worked in while they still belong to it,
     * otherwise their oldest membership.
     */
    private function lastWorkedIn(User $user, mixed $organizationId): ?OrganizationMember
    {
        $remembered = is_numeric($organizationId)
            ? $user->memberships()->firstWhere('organization_id', (int) $organizationId)
            : null;

        return $remembered ?? $user->memberships()->oldest('id')->first();
    }
}
