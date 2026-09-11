<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetCurrentOrganization;
use App\Models\OrganizationInvitation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AcceptedInvitationController extends Controller
{
    public const SESSION_TOKEN_KEY = 'invitation_token';

    public const SESSION_EMAIL_KEY = 'invitation_email';

    public function edit(Request $request, string $token): View
    {
        $invitation = OrganizationInvitation::findByPlainToken($token);

        if ($invitation === null) {
            abort(404);
        }

        $request->session()->put(self::SESSION_TOKEN_KEY, $token);
        $request->session()->put(self::SESSION_EMAIL_KEY, $invitation->email);
        $request->session()->put('url.intended', route('invitation-acceptances.edit', $token));

        return view('invitation-acceptances.edit', [
            'invitation' => $invitation,
            'token' => $token,
        ]);
    }

    public function update(Request $request, string $token): RedirectResponse
    {
        $invitation = OrganizationInvitation::findByPlainToken($token);

        if ($invitation === null) {
            abort(404);
        }

        $user = $request->user();

        if ($user === null) {
            return redirect()->route('invitation-acceptances.edit', $token);
        }

        if (! $invitation->isPending()) {
            return redirect()
                ->route('invitation-acceptances.edit', $token)
                ->with('error', $invitation->isAccepted()
                    ? 'This invitation has already been used.'
                    : 'This invitation has expired.');
        }

        if (strtolower($user->email) !== $invitation->email) {
            return redirect()
                ->route('invitation-acceptances.edit', $token)
                ->with('error', 'This invitation was sent to '.$invitation->email.'.');
        }

        DB::transaction(function () use ($invitation, $user): void {
            if ($invitation->organization->roleFor($user) === null) {
                $invitation->organization->members()->create([
                    'user_id' => $user->getKey(),
                    'role' => $invitation->role,
                ]);
            }

            $invitation->update(['accepted_at' => now()]);
        });

        $request->session()->put(SetCurrentOrganization::SESSION_KEY, $invitation->organization_id);
        $request->session()->forget([self::SESSION_TOKEN_KEY, self::SESSION_EMAIL_KEY]);

        return redirect()
            ->route('members.index', $invitation->organization)
            ->with('success', 'You have joined '.$invitation->organization->name.'.');
    }
}
