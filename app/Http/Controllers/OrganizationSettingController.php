<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateOrganizationSettingRequest;
use App\Models\Project;
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
        $organization = $this->current->organization();
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
