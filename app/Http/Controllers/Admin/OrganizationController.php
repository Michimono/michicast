<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProjectStatus;
use App\Enums\QuotaMetric;
use App\Http\Controllers\Controller;
use App\Models\AdminActionLog;
use App\Models\Broadcast;
use App\Models\ContentDelivery;
use App\Models\Organization;
use App\Models\OrganizationQuotaOverride;
use App\Models\OrganizationQuotaPeriod;
use App\Models\Project;
use App\Models\ProjectRun;
use App\Support\ProjectHealthResolver;
use App\Support\QuotaMeter;
use App\Support\Quotas;
use App\Support\ResourceQuotas;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class OrganizationController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim($request->string('q')->toString());
        $since = now()->subDay();

        $organizations = Organization::query()
            ->withCount([
                'members',
                'projects as active_broadcasts_count' => fn (Builder $query) => $query
                    ->where('status', ProjectStatus::Active),
                'runs as runs_last_24h_count' => fn (Builder $query) => $query
                    ->where('started_at', '>=', $since),
            ])
            ->addSelect([
                'deliveries_last_24h_count' => ContentDelivery::query()
                    ->selectRaw('count(*)')
                    ->where('attempted_at', '>=', $since)
                    ->whereIn(
                        'broadcast_id',
                        Broadcast::query()
                            ->select('id')
                            ->whereIn(
                                'project_id',
                                Project::query()
                                    ->select('id')
                                    ->whereColumn('organization_id', 'organizations.id'),
                            ),
                    ),
            ])
            ->when(
                $search !== '',
                fn (Builder $query) => $query->where(function (Builder $query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('slug', 'like', '%'.$search.'%');
                }),
            )
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        return view('admin.organizations.index', [
            'organizations' => $organizations,
            'search' => $search,
        ]);
    }

    public function show(
        Organization $organization,
        ProjectHealthResolver $health,
        Quotas $quotas,
        QuotaMeter $meter,
        ResourceQuotas $resourceQuotas,
    ): View {
        $organization->loadMissing('suspendedBy');

        $members = $organization->members()
            ->with('user')
            ->get()
            ->sortBy(fn ($member): string => $member->user->name)
            ->values();

        $projects = $organization->projects()
            ->oldest('id')
            ->get();

        $projectIds = $projects->modelKeys();

        /** @var Collection<int, ProjectRun> $lastLiveRuns */
        $lastLiveRuns = ProjectRun::query()
            ->whereIn('project_id', $projectIds)
            ->where('is_dry', false)
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get()
            ->unique('project_id')
            ->keyBy('project_id');

        /** @var Collection<int|string, Collection<int, Broadcast>> $autoPausedByProject */
        $autoPausedByProject = Broadcast::query()
            ->whereIn('project_id', $projectIds)
            ->notArchived()
            ->whereNotNull('failure_pause_reason')
            ->oldest('id')
            ->get()
            ->groupBy('project_id');

        $broadcasts = $projects->map(function (Project $project) use ($health, $lastLiveRuns, $autoPausedByProject, $organization): array {
            $project->setRelation('organization', $organization);

            /** @var Collection<int, Broadcast> $autoPaused */
            $autoPaused = $autoPausedByProject->get($project->getKey(), collect());

            return [
                'project' => $project,
                'health' => $health->resolve(
                    $project,
                    $lastLiveRuns->get($project->getKey()),
                    $autoPaused,
                ),
            ];
        });

        $recentRuns = ProjectRun::query()
            ->with('project')
            ->whereIn('project_id', $projectIds)
            ->latest('started_at')
            ->latest('id')
            ->limit(20)
            ->get();

        $auditEntries = AdminActionLog::query()
            ->with('admin')
            ->where('subject_type', $organization->getMorphClass())
            ->where('subject_id', $organization->getKey())
            ->latest('created_at')
            ->latest('id')
            ->limit(30)
            ->get();

        $overrides = OrganizationQuotaOverride::query()
            ->where('organization_id', $organization->getKey())
            ->get()
            ->keyBy(fn (OrganizationQuotaOverride $override): string => $override->metric->value);

        $periodSettings = OrganizationQuotaPeriod::query()
            ->where('organization_id', $organization->getKey())
            ->get()
            ->keyBy(fn (OrganizationQuotaPeriod $setting): string => $setting->metric->value);

        $quotaRows = [];

        foreach (QuotaMetric::cases() as $metric) {
            /** @var OrganizationQuotaOverride|null $override */
            $override = $overrides->get($metric->value);
            $used = $metric->isRate()
                ? $meter->used($organization, $metric)
                : $resourceQuotas->count($organization, $metric);

            $quotaRows[] = [
                'metric' => $metric,
                'label' => $metric->label(),
                'plan_limit' => $quotas->planLimitFor($organization, $metric),
                'has_override' => $override !== null,
                'override_limit' => $override?->limit,
                'override_note' => $override?->note,
                'effective_limit' => $quotas->limitFor($organization, $metric),
                'used' => $used,
                'is_rate' => $metric->isRate(),
                'period' => $metric->isRate() ? $quotas->periodFor($organization, $metric) : null,
                'plan_period' => $metric->isRate() ? $quotas->planPeriodFor($organization, $metric) : null,
                'has_period_setting' => $periodSettings->has($metric->value),
                'period_start' => $metric->isRate() ? $meter->periodStartFor($organization, $metric) : null,
            ];
        }

        return view('admin.organizations.show', [
            'organization' => $organization,
            'members' => $members,
            'broadcasts' => $broadcasts,
            'recentRuns' => $recentRuns,
            'auditEntries' => $auditEntries,
            'planOptions' => $quotas->planOptions(),
            'resolvedPlan' => $quotas->planName($organization),
            'quotaRows' => $quotaRows,
        ]);
    }
}
