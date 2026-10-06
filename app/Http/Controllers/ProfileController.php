<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfileRequest;
use App\Support\QrSvg;
use App\Support\Totp;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProfileController extends Controller
{
    public function edit(Request $request, Totp $totp, QrSvg $qr): View
    {
        $user = $request->user();
        $qrSvg = null;
        $manualSecret = null;

        if ($user->hasTwoFactorPending() && $user->two_factor_secret !== null) {
            $issuer = (string) config('app.name');
            $uri = $totp->provisioningUri($user->two_factor_secret, (string) $user->email, $issuer);
            $qrSvg = $qr->render($uri);
            $manualSecret = $user->two_factor_secret;
        }

        return view('profile.edit', [
            'user' => $user,
            'timezones' => DateTimeZone::listIdentifiers(),
            'twoFactorQrSvg' => $qrSvg,
            'twoFactorSecret' => $manualSecret,
            'recoveryCodes' => $request->session()->pull('two_factor_recovery_codes'),
        ]);
    }

    public function update(UpdateProfileRequest $request): RedirectResponse
    {
        $request->user()->update($request->validated());

        return redirect()
            ->route('profile.edit')
            ->with('success', 'Your profile has been updated.');
    }
}
