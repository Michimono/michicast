<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminAction;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use App\Support\RecordAdminAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ApprovedOrganizationController extends Controller
{
    public function store(Request $request, Organization $organization, RecordAdminAction $record): RedirectResponse
    {
        if (! $organization->isPendingApproval()) {
            return back()->with('error', 'This organization is already approved.');
        }

        /** @var User $admin */
        $admin = $request->user();

        DB::transaction(function () use ($organization, $admin, $record): void {
            $organization->forceFill([
                'approved_at' => now(),
                'approved_by' => $admin->getKey(),
            ])->save();

            $organization->recalculateActiveProjectSchedules();

            $record(
                AdminAction::Approved,
                $organization,
                null,
                [
                    'approved_at' => [
                        'before' => null,
                        'after' => $organization->approved_at?->toIso8601String(),
                    ],
                ],
            );
        });

        return redirect()
            ->route('admin.organizations.show', $organization)
            ->with('success', $organization->name.' has been approved. Its members can start building now.');
    }
}
