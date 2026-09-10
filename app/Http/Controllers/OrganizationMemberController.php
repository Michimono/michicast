<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Organization;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class OrganizationMemberController extends Controller
{
    public function index(Organization $organization): View
    {
        Gate::authorize('view', $organization);

        return view('organization-members.index', [
            'organization' => $organization,
            'members' => $organization->members()->with('user')->oldest('id')->get(),
            'roles' => Role::options(),
        ]);
    }
}
