<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfilePasswordRequest;
use Illuminate\Http\RedirectResponse;

class ProfilePasswordController extends Controller
{
    public function edit(): RedirectResponse
    {
        return redirect(route('profile.edit').'#password');
    }

    public function update(UpdateProfilePasswordRequest $request): RedirectResponse
    {
        $request->user()->update([
            'password' => $request->string('password')->toString(),
        ]);

        return redirect()
            ->route('profile.edit')
            ->with('success', 'Your password has been updated.');
    }
}
