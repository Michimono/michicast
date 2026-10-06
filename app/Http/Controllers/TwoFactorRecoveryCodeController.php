<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTwoFactorRecoveryCodeRequest;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;

/**
 * Replace the current set of single-use recovery codes.
 */
class TwoFactorRecoveryCodeController extends Controller
{
    public function store(StoreTwoFactorRecoveryCodeRequest $request, Totp $totp): RedirectResponse
    {
        $user = $request->user();

        if (! $user->hasTwoFactorEnabled()) {
            return redirect(route('profile.edit').'#two-factor')
                ->with('error', 'Enable two-factor authentication before regenerating recovery codes.');
        }

        $recoveryCodes = $totp->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_recovery_codes' => $recoveryCodes,
        ])->save();

        return redirect(route('profile.edit').'#two-factor')
            ->with('success', 'New recovery codes were generated. Store them somewhere safe — they will not be shown again.')
            ->with('two_factor_recovery_codes', $recoveryCodes);
    }
}
