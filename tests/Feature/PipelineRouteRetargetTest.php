<?php

namespace Tests\Feature;

use App\Enums\DeliveryStatus;
use App\Enums\Role;
use App\Models\Broadcast;
use App\Models\ContentDelivery;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Support\ExecutingProjectRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PipelineRouteRetargetTest extends TestCase
{
    use RefreshDatabase;

    public function test_saving_a_pipeline_retargets_a_path_when_its_destination_node_changes(): void
    {
        [$user, $organization, $project, $source, $news, $internals] = $this->workspace();

        $this->save($user, $organization, $project, $this->graph($source, $news));

        $broadcast = Broadcast::query()->where('project_id', $project->getKey())->notArchived()->sole();
        $this->assertSame($news->getKey(), $broadcast->destination_id);

        $this->save($user, $organization, $project, $this->graph($source, $internals));

        $retargeted = Broadcast::query()->where('project_id', $project->getKey())->notArchived()->sole();
        $this->assertSame($broadcast->getKey(), $retargeted->getKey());
        $this->assertSame($source->getKey(), $retargeted->source_id);
        $this->assertSame($internals->getKey(), $retargeted->destination_id);
        $this->assertSame($source->name.' → '.$internals->name, $retargeted->name);
    }

    public function test_saving_a_pipeline_retargets_a_path_when_its_source_node_changes(): void
    {
        [$user, $organization, $project, $newsSource, $destination] = $this->workspace();
        $internalsSource = $this->source($project, 'PHP internals mailing list', 'internals-1', 'Working Groups');

        $this->save($user, $organization, $project, $this->graph($newsSource, $destination));
        $broadcast = Broadcast::query()->where('project_id', $project->getKey())->notArchived()->sole();

        $this->save($user, $organization, $project, $this->graph($internalsSource, $destination));

        $retargeted = $broadcast->refresh();
        $this->assertSame($internalsSource->getKey(), $retargeted->source_id);
        $this->assertSame($destination->getKey(), $retargeted->destination_id);
        $this->assertSame(1, Broadcast::query()->where('project_id', $project->getKey())->notArchived()->count());
    }

    public function test_a_run_sends_a_stale_route_to_the_destination_the_graph_points_at(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        [$user, $organization, $project, $source, $news, $internals] = $this->workspace(active: true);

        $this->save($user, $organization, $project, $this->graph($source, $internals));

        // The graph already points at the internals destination. The row was
        // left on the news destination, which is what a save used to do.
        $broadcast = Broadcast::query()->where('project_id', $project->getKey())->notArchived()->sole();
        $broadcast->destination_id = $news->getKey();
        $broadcast->save();

        $this->assertTrue($this->app->make(ExecutingProjectRunner::class)->run($project->refresh()));

        $this->assertSame($internals->getKey(), $broadcast->refresh()->destination_id);

        $delivery = ContentDelivery::query()->sole();
        $this->assertSame(DeliveryStatus::Delivered, $delivery->status);
        $this->assertSame($internals->getKey(), $delivery->destination_id);
        $this->assertSame($broadcast->getKey(), $delivery->broadcast_id);
    }

    public function test_a_run_leaves_routes_alone_when_the_graph_has_no_complete_path(): void
    {
        [$user, $organization, $project, $source, $news] = $this->workspace(active: true);
        $this->save($user, $organization, $project, $this->graph($source, $news));

        $broadcast = Broadcast::query()->where('project_id', $project->getKey())->notArchived()->sole();

        $project->graph = [
            'nodes' => [
                ['id' => 'src', 'type' => 'source', 'x' => 0, 'y' => 0, 'config' => ['source_id' => $source->getKey()]],
            ],
            'edges' => [],
        ];
        $project->save();

        $this->assertTrue($this->app->make(ExecutingProjectRunner::class)->run($project->refresh()));

        $this->assertNull($broadcast->refresh()->archived_at);
        $this->assertSame($news->getKey(), $broadcast->destination_id);
    }

    /**
     * @return array{User, Organization, Project, Source, Destination, Destination}
     */
    private function workspace(bool $active = false): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, Role::Editor)->create();
        $factory = Project::factory()->for($organization)->visual();

        if ($active) {
            $factory = $factory->active();
        }

        $project = $factory->create(['name' => 'PHP Community']);
        $source = $this->source($project, 'PHP internals mailing list', 'rfc-1', '[RFC] Working Groups');

        return [
            $user,
            $organization,
            $project,
            $source,
            Destination::factory()->for($project)->create(['name' => 'PHP news']),
            Destination::factory()->for($project)->create(['name' => 'PHP internals']),
        ];
    }

    private function source(Project $project, string $name, string $externalId, string $title): Source
    {
        return Source::factory()->create([
            'project_id' => $project->getKey(),
            'name' => $name,
            'config' => [
                'feed_url' => 'https://example.com/'.$externalId.'.xml',
                'items' => [[
                    'external_id' => $externalId,
                    'title' => $title,
                    'canonical_url' => 'https://example.com/'.$externalId,
                    'published_at' => '2026-03-15 09:00:00',
                ]],
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function graph(Source $source, Destination $destination): array
    {
        return [
            'nodes' => [
                ['id' => 'src', 'type' => 'source', 'x' => 0, 'y' => 0, 'config' => ['source_id' => $source->getKey()]],
                ['id' => 'dst', 'type' => 'destination', 'x' => 400, 'y' => 0, 'config' => ['destination_id' => $destination->getKey()]],
            ],
            'edges' => [
                ['id' => 'e1', 'from' => 'src', 'to' => 'dst'],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $graph
     */
    private function save(User $user, Organization $organization, Project $project, array $graph): void
    {
        $this->actingAs($user)
            ->patchJson(route('pipelines.update', [$organization, $project]), ['graph' => $graph])
            ->assertOk();
    }
}
