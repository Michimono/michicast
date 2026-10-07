<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSessionRequest;
use App\Models\User;
use App\Support\RegistrationSettings;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SessionController extends Controller
{
    public function create(Request $request, RegistrationSettings $registration): View
    {
        return view('session.create', [
            'email' => $request->session()->get(AcceptedInvitationController::SESSION_EMAIL_KEY),
            'registrationOpen' => $registration->acceptsRegistrations(),
        ]);
    }

    public function store(StoreSessionRequest $request): RedirectResponse
    {
        $credentials = $request->safe()->only(['email', 'password']);

        if (! Auth::attemptWhen(
            $credentials,
            fn (User $user): bool => ! $user->isBanned(),
        )) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        if ($user->hasTwoFactorEnabled()) {
            Auth::logout();

            $request->session()->put(TwoFactorChallengeController::SESSION_USER_ID, $user->getKey());
            $request->session()->put('login.remember', $request->boolean('remember'));
            $request->session()->regenerate();

            return redirect()->route('two-factor-challenge.create');
        }

        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home');
    }
}
