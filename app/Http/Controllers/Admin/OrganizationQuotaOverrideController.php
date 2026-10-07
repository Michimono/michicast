<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AdminAction;
use App\Enums\QuotaMetric;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DestroyOrganizationQuotaOverrideRequest;
use App\Http\Requests\Admin\StoreOrganizationQuotaOverrideRequest;
use App\Models\Organization;
use App\Models\OrganizationQuotaOverride;
use App\Support\Quotas;
use App\Support\RecordAdminAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class OrganizationQuotaOverrideController extends Controller
{
    public function store(
        StoreOrganizationQuotaOverrideRequest $request,
        Organization $organization,
        Quotas $quotas,
        RecordAdminAction $record,
    ): RedirectResponse {
        $metric = QuotaMetric::from($request->string('metric')->toString());
        $limit = $request->resolvedLimit();
        $note = $request->filled('note') ? $request->string('note')->toString() : null;
        $reason = $request->string('reason')->toString();

        $existing = OrganizationQuotaOverride::query()
            ->where('organization_id', $organization->getKey())
            ->where('metric', $metric)
            ->first();

        $beforeLimit = $existing?->limit;
        $beforeHadOverride = $existing !== null;
        $beforeEffective = $quotas->limitFor($organization, $metric);

        DB::transaction(function () use (
            $organization,
            $metric,
            $limit,
            $note,
            $reason,
            $existing,
            $beforeLimit,
            $beforeHadOverride,
            $beforeEffective,
            $quotas,
            $record,
        ): void {
            if ($existing !== null) {
                $existing->forceFill([
                    'limit' => $limit,
                    'note' => $note,
                ])->save();
            } else {
                OrganizationQuotaOverride::query()->create([
                    'organization_id' => $organization->getKey(),
                    'metric' => $metric,
                    'limit' => $limit,
                    'note' => $note,
                ]);
            }

            $organization->refresh();

            $record(
                AdminAction::QuotaOverrideSet,
                $organization,
                $reason,
                [
                    'metric' => $metric->value,
                    'override_limit' => [
                        'before' => $beforeHadOverride ? $beforeLimit : '(plan)',
                        'after' => $limit,
                    ],
                    'effective_limit' => [
                        'before' => $beforeEffective,
                        'after' => $quotas->limitFor($organization, $metric),
                    ],
                    'note' => $note,
                ],
            );
        });

        $limitLabel = $limit === null ? 'unlimited' : (string) $limit;

        return redirect()
            ->route('admin.organizations.show', $organization)
            ->with('success', $metric->label().' override set to '.$limitLabel.'.');
    }

    public function destroy(
        DestroyOrganizationQuotaOverrideRequest $request,
        Organization $organization,
        Quotas $quotas,
        RecordAdminAction $record,
    ): RedirectResponse {
        $metric = QuotaMetric::from($request->string('metric')->toString());
        $reason = $request->string('reason')->toString();

        $existing = OrganizationQuotaOverride::query()
            ->where('organization_id', $organization->getKey())
            ->where('metric', $metric)
            ->first();

        if ($existing === null) {
            return back()->with('error', 'No override exists for '.$metric->label().'.');
        }

        $beforeLimit = $existing->limit;
        $beforeEffective = $quotas->limitFor($organization, $metric);

        DB::transaction(function () use (
            $organization,
            $metric,
            $reason,
            $existing,
            $beforeLimit,
            $beforeEffective,
            $quotas,
            $record,
        ): void {
            $existing->delete();
            $organization->refresh();

            $record(
                AdminAction::QuotaOverrideCleared,
                $organization,
                $reason,
                [
                    'metric' => $metric->value,
                    'override_limit' => [
                        'before' => $beforeLimit,
                        'after' => null,
                    ],
                    'effective_limit' => [
                        'before' => $beforeEffective,
                        'after' => $quotas->limitFor($organization, $metric),
                    ],
                ],
            );
        });

        return redirect()
            ->route('admin.organizations.show', $organization)
            ->with('success', $metric->label().' override cleared; plan limit applies.');
    }
}
