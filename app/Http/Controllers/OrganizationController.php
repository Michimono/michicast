<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreOrganizationRequest;
use App\Models\Organization;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OrganizationController extends Controller
{
    public function create(Request $request): View
    {
        return view('organizations.create', [
            'timezone' => $request->user()->timezone,
            'timezones' => DateTimeZone::listIdentifiers(),
        ]);
    }

    public function store(StoreOrganizationRequest $request): RedirectResponse
    {
        $name = $request->string('name')->toString();

        $request->user()->ownedOrganizations()->create([
            'name' => $name,
            'slug' => Organization::uniqueSlugFrom($name),
            'timezone' => $request->string('timezone')->toString(),
        ]);

        return redirect()
            ->route('dashboard')
            ->with('success', 'Your organization has been created.');
    }
}
