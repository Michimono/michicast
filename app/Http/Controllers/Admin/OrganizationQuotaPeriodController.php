<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminAction;
use App\Enums\QuotaMetric;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreOrganizationQuotaPeriodRequest;
use App\Models\Organization;
use App\Models\OrganizationQuotaPeriod;
use App\Support\Quotas;
use App\Support\RecordAdminAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class OrganizationQuotaPeriodController extends Controller
{
    public function store(
        StoreOrganizationQuotaPeriodRequest $request,
        Organization $organization,
        Quotas $quotas,
        RecordAdminAction $record,
    ): RedirectResponse {
        $metric = QuotaMetric::from($request->string('metric')->toString());
        $period = $request->resolvedPeriod();
        $reason = $request->string('reason')->toString();

        $existing = OrganizationQuotaPeriod::query()
            ->where('organization_id', $organization->getKey())
            ->where('metric', $metric)
            ->first();

        $beforeSetting = $existing?->period;
        $beforeEffective = $quotas->periodFor($organization, $metric);

        if ($beforeSetting === $period) {
            return redirect()
                ->route('admin.organizations.show', $organization)
                ->with('success', $metric->label().' period unchanged.');
        }

        DB::transaction(function () use ($organization, $metric, $period, $reason, $existing, $beforeSetting, $beforeEffective, $quotas, $record): void {
            if ($period === null) {
                $existing?->delete();
            } elseif ($existing !== null) {
                $existing->forceFill(['period' => $period])->save();
            } else {
                OrganizationQuotaPeriod::query()->create([
                    'organization_id' => $organization->getKey(),
                    'metric' => $metric,
                    'period' => $period,
                ]);
            }

            $record(
                AdminAction::QuotaPeriodSet,
                $organization,
                $reason,
                [
                    'metric' => $metric->value,
                    'period_setting' => [
                        'before' => $beforeSetting->value ?? '(plan)',
                        'after' => $period->value ?? '(plan)',
                    ],
                    'effective_period' => [
                        'before' => $beforeEffective->value,
                        'after' => $quotas->periodFor($organization, $metric)->value,
                    ],
                    'plan_period' => $quotas->planPeriodFor($organization, $metric)->value,
                ],
            );
        });

        $effective = $quotas->periodFor($organization, $metric);

        return redirect()
            ->route('admin.organizations.show', $organization)
            ->with('success', $metric->label().' now counts '.strtolower($effective->label()).($period === null ? ' (plan default).' : '.'));
    }
}
