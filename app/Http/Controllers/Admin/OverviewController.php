<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ProjectStatus;
use App\Enums\RunStatus;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectRun;
use App\Models\User;
use App\Support\SourceHealth;
use App\Support\SourceProviderRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;
use Laravel\Horizon\Horizon;

class OverviewController extends Controller
{
    public function __invoke(SourceHealth $health, SourceProviderRegistry $sources): View
    {
        $runsByStatus = ProjectRun::query()
            ->where('started_at', '>=', now()->subDay())
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $runCounts = collect(RunStatus::cases())->mapWithKeys(
            fn (RunStatus $status): array => [
                $status->value => (int) ($runsByStatus[$status->value] ?? 0),
            ],
        );

        return view('admin.overview', [
            'userCount' => User::query()->count(),
            'organizationCount' => Organization::query()->count(),
            'pendingOrganizationCount' => Organization::query()->whereNull('approved_at')->count(),
            'activeBroadcastCount' => Project::query()->where('status', ProjectStatus::Active)->count(),
            'runCounts' => $runCounts,
            'failedJobCount' => $this->failedJobCount(),
            'sourceHealth' => $health->all(array_map('strval', array_keys($sources->options()))),
        ]);
    }

    /**
     * Failed queue jobs when Horizon (and its failed_jobs table) is present.
     */
    private function failedJobCount(): ?int
    {
        if (! class_exists(Horizon::class) || ! Schema::hasTable('failed_jobs')) {
            return null;
        }

        return (int) DB::table('failed_jobs')->count();
    }
}
