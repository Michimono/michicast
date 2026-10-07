<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminAction;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreBannedUserRequest;
use App\Models\Organization;
use App\Models\User;
use App\Support\RecordAdminAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BannedUserController extends Controller
{
    public function store(StoreBannedUserRequest $request, User $user, RecordAdminAction $record): RedirectResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        if ($user->is($admin)) {
            return back()->with('error', 'You cannot ban your own account.');
        }

        if ($user->isPlatformAdmin()) {
            return back()->with('error', 'Platform admins cannot be banned. Revoke admin access from the CLI first.');
        }

        if ($user->isBanned()) {
            return back()->with('error', 'This user is already banned.');
        }

        $reason = $request->string('reason')->toString();
        $suspendSoleOwned = $request->boolean('suspend_sole_owned_organizations');
        $previousRememberToken = $user->remember_token;

        DB::transaction(function () use ($user, $admin, $reason, $suspendSoleOwned, $previousRememberToken, $record): void {
            $user->forceFill([
                'banned_at' => now(),
                'ban_reason' => $reason,
                'banned_by' => $admin->getKey(),
                'remember_token' => Str::random(60),
            ])->save();

            DB::table('sessions')->where('user_id', $user->getKey())->delete();

            $soleOwnedOrganizations = $user->memberships()
                ->where('role', Role::Owner)
                ->with('organization.members')
                ->get()
                ->filter(fn ($membership): bool => $membership->isLastOwner())
                ->map(fn ($membership) => $membership->organization)
                ->values();

            $soleOwnedOrganizationIds = $soleOwnedOrganizations
                ->map(fn (Organization $organization) => $organization->getKey())
                ->all();

            $suspendedOrganizationIds = [];

            if ($suspendSoleOwned) {
                foreach ($soleOwnedOrganizations as $organization) {
                    if ($organization->isSuspended()) {
                        continue;
                    }

                    $suspensionReason = 'Sole owner banned: '.$reason;

                    $organization->forceFill([
                        'suspended_at' => now(),
                        'suspension_reason' => $suspensionReason,
                        'suspended_by' => $admin->getKey(),
                    ])->save();

                    $record(
                        AdminAction::Suspended,
                        $organization,
                        $suspensionReason,
                        [
                            'suspended_at' => [
                                'before' => null,
                                'after' => $organization->suspended_at?->toIso8601String(),
                            ],
                            'from_user_ban' => $user->getKey(),
                        ],
                    );

                    $suspendedOrganizationIds[] = $organization->getKey();
                }
            }

            $changes = [
                'banned_at' => ['before' => null, 'after' => $user->banned_at?->toIso8601String()],
                'remember_token_rotated' => $previousRememberToken !== $user->remember_token,
                'suspend_sole_owned_organizations' => $suspendSoleOwned,
                'sole_owned_organization_ids' => $soleOwnedOrganizationIds,
                'suspended_organization_ids' => $suspendedOrganizationIds,
            ];

            $record(
                AdminAction::Banned,
                $user,
                $reason,
                $changes,
            );
        });

        return redirect()
            ->route('admin.users.show', $user)
            ->with('success', $user->name.' has been banned.');
    }

    public function destroy(User $user, RecordAdminAction $record): RedirectResponse
    {
        if (! $user->isBanned()) {
            return back()->with('error', 'This user is not banned.');
        }

        $reason = $user->ban_reason;
        $previousBannedAt = $user->banned_at;

        DB::transaction(function () use ($user, $reason, $previousBannedAt, $record): void {
            $user->forceFill([
                'banned_at' => null,
                'ban_reason' => null,
                'banned_by' => null,
            ])->save();

            $record(
                AdminAction::Unbanned,
                $user,
                $reason,
                [
                    'banned_at' => [
                        'before' => $previousBannedAt?->toIso8601String(),
                        'after' => null,
                    ],
                ],
            );
        });

        return redirect()
            ->route('admin.users.show', $user)
            ->with('success', $user->name.' has been unbanned. Existing sessions were not restored.');
    }
}
