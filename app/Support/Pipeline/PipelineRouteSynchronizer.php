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
 * Every canvas path owns one row; paths from the same source node to the
 * same destination node share a route group so the runner sends an item
 * once per group. Rows are matched by the path's key
 * first; a path whose key is new (a node was added or removed along it)
 * takes over an unclaimed row for the same source and destination, so
 * redrawing the graph keeps the delivery history and any auto-pause. Rows no
 * longer in the graph are archived, never deleted; a row that comes back is
 * restored.
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
            $existing = $project->broadcasts()->orderBy('id')->get();
            $pathKeys = [];

            foreach ($routes as $route) {
                $pathKeys[$route->pathKey()] = true;
            }

            /** @var array<int, Broadcast> $claimed  Route index => row. */
            $claimed = [];
            /** @var array<int, true> $kept  Row id => true. */
            $kept = [];

            // Exact path matches first, so a reshuffle never steals a row
            // that still belongs to an unchanged path.
            foreach ($routes as $index => $route) {
                $match = $existing->first(
                    fn (Broadcast $broadcast): bool => $broadcast->route_key === $route->pathKey()
                        && ! isset($kept[(int) $broadcast->getKey()]),
                );

                if ($match instanceof Broadcast) {
                    $claimed[$index] = $match;
                    $kept[(int) $match->getKey()] = true;
                }
            }

            // Then any unclaimed row for the same pair whose path is gone
            // (or that predates path keys).
            foreach ($routes as $index => $route) {
                if (isset($claimed[$index])) {
                    continue;
                }

                $match = $existing->first(
                    fn (Broadcast $broadcast): bool => (int) $broadcast->source_id === $route->sourceId
                        && (int) $broadcast->destination_id === $route->destinationId
                        && ! isset($kept[(int) $broadcast->getKey()])
                        && ($broadcast->route_key === null || ! isset($pathKeys[$broadcast->route_key])),
                );

                if ($match instanceof Broadcast) {
                    $claimed[$index] = $match;
                    $kept[(int) $match->getKey()] = true;
                }
            }

            $live = [];

            foreach ($routes as $index => $route) {
                $match = $claimed[$index] ?? null;

                if ($match instanceof Broadcast) {
                    $match->fill([
                        'name' => $route->name,
                        ...$route->rowKeys(),
                        'filter' => $route->filter,
                        'transform' => $route->transform,
                        'mapping' => $route->mapping,
                    ]);
                    $match->save();

                    if ($match->isArchived()) {
                        $match->restore();
                    }

                    $live[] = $match;

                    continue;
                }

                $project->loadMissing('organization');

                $created = $project->broadcasts()->create([
                    'name' => $route->name,
                    'source_id' => $route->sourceId,
                    'destination_id' => $route->destinationId,
                    ...$route->rowKeys(),
                    'filter' => $route->filter,
                    'transform' => $route->transform,
                    'mapping' => $route->mapping,
                    'enabled' => ! $this->quotas->wouldExceed($project->organization, QuotaMetric::ActiveBroadcasts),
                ]);

                $kept[(int) $created->getKey()] = true;
                $live[] = $created;
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
