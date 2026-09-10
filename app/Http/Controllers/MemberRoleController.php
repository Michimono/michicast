<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Requests\UpdateMemberRoleRequest;
use App\Models\OrganizationMember;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class MemberRoleController extends Controller
{
    public function edit(OrganizationMember $member): View
    {
        Gate::authorize('update', $member);

        return view('member-roles.edit', [
            'member' => $member,
            'roles' => Role::options(),
        ]);
    }

    public function update(UpdateMemberRoleRequest $request, OrganizationMember $member): RedirectResponse
    {
        $member->update(['role' => $request->enum('role', Role::class)]);

        return redirect()
            ->route('members.index')
            ->with('success', 'The role has been updated.');
    }
}
