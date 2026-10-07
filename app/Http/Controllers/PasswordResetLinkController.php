<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePasswordResetLinkRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    public function create(): View
    {
        return view('password-reset-links.create');
    }

    public function store(StorePasswordResetLinkRequest $request): RedirectResponse
    {
        $email = $request->validated('email');
        $user = User::query()->where('email', $email)->first();

        // Same success response as a sent link so bans are not enumerable.
        if ($user instanceof User && $user->isBanned()) {
            return back()->with('success', __(Password::ResetLinkSent));
        }

        $status = Password::sendResetLink($request->validated());

        if ($status !== Password::ResetLinkSent) {
            throw ValidationException::withMessages([
                'email' => __($status),
            ]);
        }

        return back()->with('success', __($status));
    }
}
