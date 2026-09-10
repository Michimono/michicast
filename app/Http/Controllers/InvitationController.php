<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\StoreInvitationRequest;
use App\Mail\OrganizationInvitationMail;
use App\Models\OrganizationInvitation;
use App\Support\CurrentOrganization;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;

class InvitationController extends Controller
{
    public function __construct(private CurrentOrganization $current) {}

    public function store(StoreInvitationRequest $request): RedirectResponse
    {
        $organization = $this->current->organization();
        $email = $request->normalizedEmail();
        $plain = OrganizationInvitation::generatePlainToken();

        $organization->invitations()
            ->pending()
            ->where('email', $email)
            ->delete();

        $invitation = $organization->invitations()->create([
            'invited_by' => $request->user()->getKey(),
            'email' => $email,
            'role' => $request->enum('role', Role::class),
            'token' => OrganizationInvitation::hashToken($plain),
            'expires_at' => now()->addDays(OrganizationInvitation::EXPIRES_AFTER_DAYS),
        ]);

        Mail::to($email)->send(new OrganizationInvitationMail(
            $invitation->load(['organization', 'inviter']),
            route('invitation-acceptances.edit', $plain),
        ));

        return redirect()
            ->route('members.index')
            ->with('success', 'Invitation sent.');
    }

    public function destroy(OrganizationInvitation $invitation): RedirectResponse
    {
        if ($invitation->organization()->isNot($this->current->organization())) {
            abort(404);
        }

        Gate::authorize('delete', $invitation);

        $invitation->delete();

        return redirect()
            ->route('members.index')
            ->with('success', 'The invitation has been cancelled.');
    }
}
