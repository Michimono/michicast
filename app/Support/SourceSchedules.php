<?php

namespace App\Support;

use App\Models\Project;
use App\Models\Source;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Per-source schedules of a visual broadcast.
 *
 * A source is live when an enabled, unarchived route reads it. Each live
 * source keeps its own next_run_at; the broadcast's next_run_at is the
 * earliest of them, so the dispatcher still claims whole broadcasts and the
 * run only reads the sources that are due. Sources that are not live carry
 * no next run, so wiring one back in schedules it from then instead of
 * firing a stale time.
 */
class SourceSchedules
{
    public function __construct(private NextRunCalculator $calculator) {}

    /**
     * Bring every source's next run up to date and return the broadcast's.
     *
     * @param  bool|list<int>  $reschedule  True recomputes every live source from $from,
     *                                      a list recomputes only those, and a live source
     *                                      without a next run is always computed.
     */
    public function refresh(Project $project, CarbonInterface $from, bool|array $reschedule = false): ?Carbon
    {
        $live = $this->liveSourceIds($project);
        $earliest = null;

        /** @var Collection<int, Source> $sources */
        $sources = $project->sources()->get();

        foreach ($sources as $source) {
            $source->setRelation('project', $project);

            if (! in_array((int) $source->getKey(), $live, true)) {
                if ($source->next_run_at !== null) {
                    $source->next_run_at = null;
                    $source->save();
                }

                continue;
            }

            $recompute = $reschedule === true
                || $source->next_run_at === null
                || (is_array($reschedule) && in_array((int) $source->getKey(), $reschedule, true));

            if ($recompute) {
                $source->next_run_at = $this->nextFor($source, $project, $from);
                $source->save();
            }

            if ($source->next_run_at !== null && ($earliest === null || $source->next_run_at->lt($earliest))) {
                $earliest = $source->next_run_at->copy();
            }
        }

        return $earliest;
    }

    /**
     * Live sources whose next run has come.
     *
     * @return list<int>
     */
    public function dueSourceIds(Project $project, CarbonInterface $now): array
    {
        $live = $this->liveSourceIds($project);

        if ($live === []) {
            return [];
        }

        return $project->sources()
            ->whereKey($live)
            ->whereNotNull('next_run_at')
            ->where('next_run_at', '<=', $now)
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }

    public function nextFor(Source $source, Project $project, CarbonInterface $from): ?Carbon
    {
        $next = $this->calculator->next(
            $source->schedule ?? $project->schedule,
            $project->effectiveTimezone(),
            $from,
        );

        return $next === null ? null : Carbon::instance($next);
    }

    /**
     * @return list<int>
     */
    public function liveSourceIds(Project $project): array
    {
        return $project->broadcasts()
            ->runnable()
            ->whereHas('source', fn ($query) => $query->whereNull('archived_at'))
            ->distinct()
            ->pluck('source_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();
    }
}
