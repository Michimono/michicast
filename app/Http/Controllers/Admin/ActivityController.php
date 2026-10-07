<?php

namespace App\Http\Controllers\Admin;

use App\Enums\QuotaMetric;
use App\Enums\RunStatus;
use App\Http\Controllers\Controller;
use App\Models\Broadcast;
use App\Models\BroadcastRun;
use App\Models\Organization;
use App\Models\OrganizationUsage;
use App\Support\QuotaMeter;
use App\Support\Quotas;
use App\Support\SecretRedactor;
use App\Support\SourceProviderRegistry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class ActivityController extends Controller
{
    /**
     * @var array<string, string>
     */
    private const TIME_WINDOWS = [
        '24h' => 'Last 24 hours',
        '7d' => 'Last 7 days',
        '30d' => 'Last 30 days',
    ];

    public function __construct(
        private SecretRedactor $secrets,
        private SourceProviderRegistry $sources,
        private Quotas $quotas,
        private QuotaMeter $meter,
    ) {}

    public function __invoke(Request $request): View
    {
        $status = RunStatus::tryFrom($request->string('status')->toString());
        $organizationId = $request->integer('organization_id') ?: null;
        $sourceType = $request->string('source_type')->toString();
        $window = $request->string('window')->toString();
        $since = $this->sinceFromWindow($window);

        $runs = BroadcastRun::query()
            ->with([
                'broadcast.source:id,name,type',
                'broadcast.destination:id,name,type',
                'broadcast.project:id,name,organization_id',
                'broadcast.project.organization:id,name,slug',
            ])
            ->when(
                $status !== null,
                fn (Builder $query) => $query->where('status', $status),
            )
            ->when(
                $organizationId !== null,
                fn (Builder $query) => $query->whereHas(
                    'broadcast.project',
                    fn (Builder $query) => $query->where('organization_id', $organizationId),
                ),
            )
            ->when(
                $sourceType !== '',
                fn (Builder $query) => $query->whereHas(
                    'broadcast.source',
                    fn (Builder $query) => $query->where('type', $sourceType),
                ),
            )
            ->when(
                $since !== null,
                fn (Builder $query) => $query->where('started_at', '>=', $since),
            )
            ->latest('started_at')
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('admin.activity', [
            'runs' => $runs,
            'secrets' => $this->secrets,
            'organizations' => Organization::query()->orderBy('name')->get(['id', 'name']),
            'sourceTypeOptions' => $this->sources->options(),
            'statusOptions' => collect(RunStatus::cases())->mapWithKeys(
                fn (RunStatus $case): array => [$case->value => $case->label()],
            ),
            'timeWindows' => self::TIME_WINDOWS,
            'filters' => [
                'status' => $status?->value,
                'organization_id' => $organizationId,
                'source_type' => $sourceType,
                'window' => array_key_exists($window, self::TIME_WINDOWS) ? $window : '',
            ],
            'autoPaused' => $this->autoPausedBroadcasts(),
            'outOfQuota' => $this->organizationsOutOfQuotaToday(),
            'topByFetches' => $this->topOrganizationsByFetches(),
            'topByDeliveries' => $this->topOrganizationsByDeliveries(),
        ]);
    }

    /**
     * Broadcasts disabled after a failure streak, newest first.
     *
     * @return Collection<int, Broadcast>
     */
    private function autoPausedBroadcasts(): Collection
    {
        return Broadcast::query()
            ->with(['project.organization:id,name,slug'])
            ->notArchived()
            ->whereNotNull('failure_pause_reason')
            ->latest('updated_at')
            ->latest('id')
            ->limit(20)
            ->get();
    }

    /**
     * Organizations whose rate usage for the current UTC day has hit a limit.
     *
     * @return list<array{organization: Organization, metric: QuotaMetric, used: int, limit: int}>
     */
    private function organizationsOutOfQuotaToday(): array
    {
        $periodStart = $this->meter->currentPeriodStart()->toDateString();

        $usages = OrganizationUsage::query()
            ->with('organization:id,name,slug')
            ->whereDate('period_start', $periodStart)
            ->whereIn('metric', [QuotaMetric::SourceFetches, QuotaMetric::Deliveries])
            ->orderByDesc('used')
            ->get();

        $rows = [];

        foreach ($usages as $usage) {
            if ($usage->organization === null) {
                continue;
            }

            $limit = $this->quotas->limitFor($usage->organization, $usage->metric);

            if ($limit === null || $usage->used < $limit) {
                continue;
            }

            $rows[] = [
                'organization' => $usage->organization,
                'metric' => $usage->metric,
                'used' => $usage->used,
                'limit' => $limit,
            ];
        }

        return $rows;
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function topOrganizationsByFetches(): Collection
    {
        $since = now()->subDay();

        return DB::table('broadcast_runs')
            ->selectRaw('projects.organization_id as organization_id, organizations.name as name, count(*) as aggregate')
            ->join('broadcasts', 'broadcasts.id', '=', 'broadcast_runs.broadcast_id')
            ->join('projects', 'projects.id', '=', 'broadcasts.project_id')
            ->join('organizations', 'organizations.id', '=', 'projects.organization_id')
            ->where('broadcast_runs.started_at', '>=', $since)
            ->groupBy('projects.organization_id', 'organizations.name')
            ->orderByDesc('aggregate')
            ->limit(5)
            ->get();
    }

    /**
     * @return Collection<int, \stdClass>
     */
    private function topOrganizationsByDeliveries(): Collection
    {
        $since = now()->subDay();

        return DB::table('content_deliveries')
            ->selectRaw('projects.organization_id as organization_id, organizations.name as name, count(*) as aggregate')
            ->join('broadcasts', 'broadcasts.id', '=', 'content_deliveries.broadcast_id')
            ->join('projects', 'projects.id', '=', 'broadcasts.project_id')
            ->join('organizations', 'organizations.id', '=', 'projects.organization_id')
            ->where('content_deliveries.attempted_at', '>=', $since)
            ->groupBy('projects.organization_id', 'organizations.name')
            ->orderByDesc('aggregate')
            ->limit(5)
            ->get();
    }

    private function sinceFromWindow(string $window): ?Carbon
    {
        return match ($window) {
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            default => null,
        };
    }
}
