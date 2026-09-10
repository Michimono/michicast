<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\StoreOrganizationRequest;
use App\Models\Organization;
use DateTimeZone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $organization = DB::transaction(function () use ($request, $name): Organization {
            $organization = Organization::create([
                'name' => $name,
                'slug' => Organization::uniqueSlugFrom($name),
                'timezone' => $request->string('timezone')->toString(),
            ]);

            $organization->members()->create([
                'user_id' => $request->user()->getKey(),
                'role' => Role::Owner,
            ]);

            return $organization;
        });

        return redirect()
            ->route('organizations.members.index', $organization)
            ->with('success', 'Your organization has been created.');
    }
}
