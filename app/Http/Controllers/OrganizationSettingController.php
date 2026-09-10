<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOrganizationSettingRequest;
use App\Support\CurrentOrganization;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class OrganizationSettingController extends Controller
{
    public function __construct(private CurrentOrganization $current) {}

    public function edit(): View
    {
        $organization = $this->current->organization();

        Gate::authorize('update', $organization);

        return view('organization-settings.edit', [
            'organization' => $organization,
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    public function update(UpdateOrganizationSettingRequest $request): RedirectResponse
    {
        $this->current->organization()->update($request->safe()->only(['name', 'timezone']));

        return redirect()
            ->route('organization-settings.edit')
            ->with('success', 'Organization settings have been updated.');
    }
}
