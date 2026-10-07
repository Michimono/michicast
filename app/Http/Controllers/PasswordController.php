<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdatePasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function edit(Request $request, string $token): View
    {
        return view('passwords.edit', [
            'token' => $token,
            'email' => $request->string('email')->toString(),
        ]);
    }

    public function update(UpdatePasswordRequest $request, string $token): RedirectResponse
    {
        $email = $request->validated('email');
        $existing = User::query()->where('email', $email)->first();

        if ($existing instanceof User && $existing->isBanned()) {
            throw ValidationException::withMessages([
                'email' => __(Password::InvalidToken),
            ]);
        }

        $status = Password::reset(
            [...$request->validated(), 'token' => $token],
            function (User $user, string $password): void {
                if ($user->isBanned()) {
                    throw ValidationException::withMessages([
                        'email' => __(Password::InvalidToken),
                    ]);
                }

                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages([
                'email' => __($status),
            ]);
        }

        return redirect()->route('session.create')->with('success', __($status));
    }
}
