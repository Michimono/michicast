<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTwoFactorChallengeRequest;
use App\Models\User;
use App\Support\Totp;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Second step after password login when the account has confirmed 2FA.
 */
class TwoFactorChallengeController extends Controller
{
    public const SESSION_USER_ID = 'login.id';

    public function create(Request $request): View|RedirectResponse
    {
        if (! $request->session()->has(self::SESSION_USER_ID)) {
            return redirect()->route('session.create');
        }

        return view('two-factor-challenges.create');
    }

    public function store(StoreTwoFactorChallengeRequest $request, Totp $totp): RedirectResponse
    {
        $userId = $request->session()->get(self::SESSION_USER_ID);
        $user = User::query()->find($userId);

        if (! $user instanceof User || ! $user->hasTwoFactorEnabled() || $user->two_factor_secret === null || $user->isBanned()) {
            $request->session()->forget(self::SESSION_USER_ID);

            return redirect()->route('session.create');
        }

        $code = $request->string('code')->toString();
        $passed = $totp->verify($user->two_factor_secret, $code);

        if (! $passed) {
            $passed = $user->consumeRecoveryCode($code);
        }

        if (! $passed) {
            throw ValidationException::withMessages([
                'code' => 'That authentication code is invalid.',
            ]);
        }

        Auth::login($user, (bool) $request->session()->pull('login.remember', false));
        $request->session()->forget(self::SESSION_USER_ID);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
