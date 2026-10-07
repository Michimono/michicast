<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateOrganizationPlanRequest;
use App\Models\Organization;
use App\Support\Quotas;
use App\Support\RecordAdminAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class OrganizationPlanController extends Controller
{
    public function update(
        UpdateOrganizationPlanRequest $request,
        Organization $organization,
        Quotas $quotas,
        RecordAdminAction $record,
    ): RedirectResponse {
        $plan = $request->string('plan')->toString();
        $reason = $request->string('reason')->toString();
        $before = $organization->plan;
        $beforeEffective = $quotas->planName($organization);

        if ($before === $plan) {
            return back()->with('error', 'Organization is already on the '.$plan.' plan.');
        }

        DB::transaction(function () use ($organization, $plan, $reason, $before, $beforeEffective, $record): void {
            $organization->forceFill(['plan' => $plan])->save();

            $record(
                AdminAction::PlanChanged,
                $organization,
                $reason,
                [
                    'plan' => [
                        'before' => $before,
                        'after' => $plan,
                    ],
                    'resolved_plan' => [
                        'before' => $beforeEffective,
                        'after' => $plan,
                    ],
                ],
            );
        });

        return redirect()
            ->route('admin.organizations.show', $organization)
            ->with('success', $organization->name.' moved to the '.$plan.' plan.');
    }
}
