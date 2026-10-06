<?php

namespace App\Http\Controllers;

use App\Http\Requests\DestroyTwoFactorAuthenticationRequest;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Begin or disable TOTP enrollment from profile settings.
 */
class TwoFactorAuthenticationController extends Controller
{
    public function create(): RedirectResponse
    {
        return redirect(route('profile.edit').'#two-factor');
    }

    public function store(Request $request, Totp $totp): RedirectResponse
    {
        $user = $request->user();

        if ($user->hasTwoFactorEnabled()) {
            return redirect(route('profile.edit').'#two-factor')
                ->with('error', 'Two-factor authentication is already enabled.');
        }

        $user->forceFill([
            'two_factor_secret' => $totp->generateSecret(),
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        return redirect(route('profile.edit').'#two-factor')
            ->with('success', 'Scan the QR code, then confirm with a code from your authenticator app.');
    }

    public function destroy(DestroyTwoFactorAuthenticationRequest $request): RedirectResponse
    {
        $request->user()->clearTwoFactorAuthentication();

        return redirect(route('profile.edit').'#two-factor')
            ->with('success', 'Two-factor authentication has been disabled.');
    }
}
