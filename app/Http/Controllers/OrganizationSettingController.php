<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOrganizationSettingRequest;
use App\Models\Organization;
use App\Models\Project;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class OrganizationSettingController extends Controller
{
    public function edit(Organization $organization): View
    {
        Gate::authorize('update', $organization);

        return view('organization-settings.edit', [
            'organization' => $organization,
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    public function update(UpdateOrganizationSettingRequest $request, Organization $organization): RedirectResponse
    {
        $organization->update($request->safe()->only(['name', 'timezone']));

        if ($organization->wasChanged('timezone')) {
            $organization->projects()->whereNull('timezone')->each(function (Project $project): void {
                $project->refreshNextRunAt();
                $project->save();
            });
        }

        return redirect()
            ->route('organization-settings.edit')
            ->with('success', 'Organization settings have been updated.');
    }
}
