<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminAction;
use App\Enums\QuotaMetric;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOrganizationQuotaUsageResetRequest;
use App\Models\Organization;
use App\Support\QuotaMeter;
use App\Support\RecordAdminAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class OrganizationQuotaUsageController extends Controller
{
    public function store(
        StoreOrganizationQuotaUsageResetRequest $request,
        Organization $organization,
        QuotaMeter $meter,
        RecordAdminAction $record,
    ): RedirectResponse {
        $metric = QuotaMetric::from($request->string('metric')->toString());
        $reason = $request->string('reason')->toString();

        DB::transaction(function () use ($organization, $metric, $reason, $meter, $record): void {
            $before = $meter->resetCurrentPeriod($organization, $metric);

            $record(
                AdminAction::QuotaUsageReset,
                $organization,
                $reason,
                [
                    'metric' => $metric->value,
                    'period_start' => $meter->currentPeriodStart()->toDateString(),
                    'used' => [
                        'before' => $before,
                        'after' => 0,
                    ],
                ],
            );
        });

        return redirect()
            ->route('admin.organizations.show', $organization)
            ->with('success', $metric->label().' usage for today reset to zero.');
    }
}
