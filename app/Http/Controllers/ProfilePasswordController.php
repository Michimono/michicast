<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateProfilePasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ProfilePasswordController extends Controller
{
    public function edit(): View
    {
        return view('profile.password.edit');
    }

    public function update(UpdateProfilePasswordRequest $request): RedirectResponse
    {
        $request->user()->update([
            'password' => $request->string('password')->toString(),
        ]);

        return redirect()
            ->route('profile.password.edit')
            ->with('success', 'Your password has been updated.');
    }
}
