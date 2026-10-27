<?php

namespace App\Support\Pipeline;

use App\Enums\QuotaMetric;
use App\Models\Broadcast;
use App\Models\Project;
use App\Support\ResourceQuotas;
use Illuminate\Support\Facades\DB;

/**
 * Writes compiled routes into the project's broadcasts rows, which is what
 * the dispatcher, the delivery history and the duplicate guard key on.
 *
 * Routes are matched to rows by source and destination record, so redrawing
 * the graph around the same pair keeps the row — and with it the delivery
 * history and any auto-pause. Rows no longer in the
 * graph are archived, never deleted; a row that comes back is restored.
 */
class PipelineRouteSynchronizer
{
    public function __construct(private ResourceQuotas $quotas) {}

    /**
     * @param  list<CompiledRoute>  $routes
     * @return list<Broadcast> The live rows backing the routes, in route order.
     */
    public function sync(Project $project, array $routes): array
    {
        return DB::transaction(function () use ($project, $routes): array {
            $existing = $project->broadcasts()->get();
            $kept = [];
            $live = [];

            foreach ($routes as $route) {
                $match = $existing->first(
                    fn (Broadcast $broadcast): bool => (int) $broadcast->source_id === $route->sourceId
                        && (int) $broadcast->destination_id === $route->destinationId,
                );

                if ($match instanceof Broadcast) {
                    // A second canvas path for the same source and destination
                    // shares this row. The first path owns the filter, transform,
                    // and mapping; rewriting them would drop that sending.
                    if (isset($kept[(int) $match->getKey()])) {
                        $live[] = $match;

                        continue;
                    }

                    $match->fill([
                        'name' => $route->name,
                        'filter' => $route->filter,
                        'transform' => $route->transform,
                        'mapping' => $route->mapping,
                    ]);
                    $match->save();

                    if ($match->isArchived()) {
                        $match->restore();
                    }

                    $kept[(int) $match->getKey()] = true;
                    $live[] = $match;

                    continue;
                }

                $project->loadMissing('organization');

                $created = $project->broadcasts()->create([
                    'name' => $route->name,
                    'source_id' => $route->sourceId,
                    'destination_id' => $route->destinationId,
                    'filter' => $route->filter,
                    'transform' => $route->transform,
                    'mapping' => $route->mapping,
                    'enabled' => ! $this->quotas->wouldExceed($project->organization, QuotaMetric::ActiveBroadcasts),
                ]);

                $kept[(int) $created->getKey()] = true;
                $live[] = $created;
                $existing->push($created);
            }

            foreach ($existing as $broadcast) {
                if (! isset($kept[(int) $broadcast->getKey()]) && ! $broadcast->isArchived()) {
                    $broadcast->archive();
                }
            }

            return $live;
        });
    }
}
