<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreConfirmedTwoFactorAuthenticationRequest;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

/**
 * Confirm a pending TOTP secret with a valid authenticator code.
 */
class ConfirmedTwoFactorAuthenticationController extends Controller
{
    public function store(StoreConfirmedTwoFactorAuthenticationRequest $request, Totp $totp): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasTwoFactorPending() || $user->two_factor_secret === null) {
            return redirect(route('profile.edit').'#two-factor')
                ->with('error', 'Start two-factor enrollment before confirming a code.');
        }

        if (! $totp->verify($user->two_factor_secret, $request->string('code')->toString())) {
            throw ValidationException::withMessages([
                'code' => 'That authentication code is invalid.',
            ]);
        }

        $recoveryCodes = $totp->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_recovery_codes' => $recoveryCodes,
        ])->save();

        return redirect(route('profile.edit').'#two-factor')
            ->with('success', 'Two-factor authentication is enabled. Store your recovery codes somewhere safe — they will not be shown again.')
            ->with('two_factor_recovery_codes', $recoveryCodes);
    }
}
