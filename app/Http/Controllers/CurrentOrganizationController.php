<?php

namespace App\Http\Controllers;

use App\Http\Middleware\SetCurrentOrganization;
use App\Http\Requests\UpdateCurrentOrganizationRequest;
use App\Models\Organization;
use Illuminate\Http\RedirectResponse;

class CurrentOrganizationController extends Controller
{
    public function update(UpdateCurrentOrganizationRequest $request): RedirectResponse
    {
        $organization = Organization::query()->findOrFail($request->integer('organization'));

        $request->session()->put(SetCurrentOrganization::SESSION_KEY, $organization->getKey());

        return redirect()
            ->route('dashboard')
            ->with('success', 'You are now working in '.$organization->name.'.');
    }
}
