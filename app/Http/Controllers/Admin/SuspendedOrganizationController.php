<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreSuspendedOrganizationRequest;
use App\Models\Organization;
use App\Models\User;
use App\Support\RecordAdminAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class SuspendedOrganizationController extends Controller
{
    public function store(
        StoreSuspendedOrganizationRequest $request,
        Organization $organization,
        RecordAdminAction $record,
    ): RedirectResponse {
        if ($organization->isSuspended()) {
            return back()->with('error', 'This organization is already suspended.');
        }

        /** @var User $admin */
        $admin = $request->user();
        $reason = $request->string('reason')->toString();

        DB::transaction(function () use ($organization, $admin, $reason, $record): void {
            $organization->forceFill([
                'suspended_at' => now(),
                'suspension_reason' => $reason,
                'suspended_by' => $admin->getKey(),
            ])->save();

            $record(
                AdminAction::Suspended,
                $organization,
                $reason,
                [
                    'suspended_at' => [
                        'before' => null,
                        'after' => $organization->suspended_at?->toIso8601String(),
                    ],
                ],
            );
        });

        return redirect()
            ->route('admin.organizations.show', $organization)
            ->with('success', $organization->name.' has been suspended.');
    }

    public function destroy(Organization $organization, RecordAdminAction $record): RedirectResponse
    {
        if (! $organization->isSuspended()) {
            return back()->with('error', 'This organization is not suspended.');
        }

        $reason = $organization->suspension_reason;
        $previousSuspendedAt = $organization->suspended_at;

        DB::transaction(function () use ($organization, $reason, $previousSuspendedAt, $record): void {
            $organization->forceFill([
                'suspended_at' => null,
                'suspension_reason' => null,
                'suspended_by' => null,
            ])->save();

            $organization->recalculateActiveProjectSchedules();

            $record(
                AdminAction::Reinstated,
                $organization,
                $reason,
                [
                    'suspended_at' => [
                        'before' => $previousSuspendedAt?->toIso8601String(),
                        'after' => null,
                    ],
                    'next_run_at_recalculated' => true,
                ],
            );
        });

        return redirect()
            ->route('admin.organizations.show', $organization)
            ->with('success', $organization->name.' has been reinstated. Schedules resume from now.');
    }
}
