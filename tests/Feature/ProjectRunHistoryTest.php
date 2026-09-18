<?php

namespace Tests\Feature;

use App\Enums\DeliveryStatus;
use App\Enums\Role;
use App\Enums\RunStatus;
use App\Models\Broadcast;
use App\Models\BroadcastRun;
use App\Models\BroadcastRunSkip;
use App\Models\ContentDelivery;
use App\Models\ContentItem;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectRun;
use App\Models\Source;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectRunHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_member_sees_run_history_for_their_project(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $project = Project::factory()->for($organization)->create();
        $older = ProjectRun::factory()->for($project)->create([
            'started_at' => now()->subHour(),
            'items_found' => 2,
            'items_delivered' => 1,
        ]);
        $newer = ProjectRun::factory()->for($project)->create([
            'started_at' => now()->subMinute(),
            'items_found' => 5,
            'items_skipped' => 1,
            'items_delivered' => 3,
            'items_failed' => 1,
        ]);
        ProjectRun::factory()->create([
            'started_at' => now(),
            'items_found' => 99,
        ]);

        $response = $this->actingAs($user)
            ->get(route('project-runs.index', [$organization, $project]));

        $response->assertOk();
        $response->assertSeeInOrder([
            (string) $newer->items_found,
            (string) $older->items_found,
        ]);
        $response->assertDontSee('found 99');
        $response->assertSee(route('project-runs.show', [$organization, $project, $newer]), false);
    }

    public function test_run_detail_names_skip_reasons_and_redacts_secrets(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $user->forceFill(['timezone' => 'America/New_York'])->save();

        $project = Project::factory()->for($organization)->create(['timezone' => 'Europe/Madrid']);
        $source = Source::factory()->for($project)->create();
        $destination = Destination::factory()->for($project)->create();
        $broadcast = Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
        ]);

        $run = ProjectRun::factory()->for($project)->create([
            'status' => RunStatus::Completed,
            'items_skipped' => 2,
            'items_failed' => 1,
            'items_delivered' => 1,
        ]);
        $broadcastRun = BroadcastRun::factory()->create([
            'project_run_id' => $run->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'items_skipped' => 2,
            'items_failed' => 1,
            'items_delivered' => 1,
        ]);

        $kept = ContentItem::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'title' => 'Kept post',
            'canonical_url' => 'https://example.com/kept',
        ]);
        $filtered = ContentItem::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'title' => 'Filtered post',
        ]);
        $duplicate = ContentItem::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'title' => 'Duplicate post',
        ]);
        $failed = ContentItem::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'title' => 'Failed post',
        ]);

        ContentDelivery::factory()->create([
            'content_item_id' => $kept->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'destination_id' => $destination->getKey(),
            'broadcast_run_id' => $broadcastRun->getKey(),
            'status' => DeliveryStatus::Delivered,
            'external_reference' => 'msg-42',
        ]);
        ContentDelivery::factory()->skipped('Title does not contain “pokemon”')->create([
            'content_item_id' => $filtered->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'destination_id' => $destination->getKey(),
            'broadcast_run_id' => $broadcastRun->getKey(),
        ]);
        ContentDelivery::factory()->failed('bot 123456789:AABotTokenLeakHere1234567890 rejected chat')->create([
            'content_item_id' => $failed->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'destination_id' => $destination->getKey(),
            'broadcast_run_id' => $broadcastRun->getKey(),
        ]);
        BroadcastRunSkip::factory()->create([
            'broadcast_run_id' => $broadcastRun->getKey(),
            'content_item_id' => $duplicate->getKey(),
            'reason' => 'Already delivered, pending, or skipped for this destination.',
        ]);

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $response = $this->actingAs($user)
            ->get(route('project-runs.show', [$organization, $project, $run]));

        $response->assertOk();
        $response->assertSee('Kept post');
        $response->assertSee('Filtered post');
        $response->assertSee('Title does not contain');
        $response->assertSee('Duplicate post');
        $response->assertSee('Already delivered, pending, or skipped for this destination.');
        $response->assertSee('Failed post');
        $response->assertSee('[redacted]');
        $response->assertDontSee('AABotTokenLeakHere1234567890');
        $response->assertSee('msg-42');
        $response->assertSee('https://example.com/kept');
        $response->assertSee('Europe/Madrid');
        $response->assertSee('America/New_York');
        $this->assertLessThan(25, $queries);
    }

    public function test_another_organizations_run_is_not_visible(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $project = Project::factory()->for($organization)->create();
        $foreign = ProjectRun::factory()->create();

        $this->actingAs($user)
            ->get(route('project-runs.show', [$organization, $project, $foreign]))
            ->assertNotFound();
    }

    public function test_delivery_history_filters_by_status(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->for($project)->create();
        $destination = Destination::factory()->for($project)->create();
        $broadcast = Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'name' => 'News route',
        ]);

        $ok = ContentItem::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'title' => 'Delivered item',
        ]);
        $bad = ContentItem::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'title' => 'Failed item',
        ]);

        ContentDelivery::factory()->create([
            'content_item_id' => $ok->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'destination_id' => $destination->getKey(),
            'status' => DeliveryStatus::Delivered,
        ]);
        ContentDelivery::factory()->failed('permanent boom')->create([
            'content_item_id' => $bad->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'destination_id' => $destination->getKey(),
        ]);

        $this->actingAs($user)
            ->get(route('broadcast-deliveries.index', [$organization, $broadcast, 'status' => 'failed']))
            ->assertOk()
            ->assertSee('Failed item')
            ->assertSee('permanent boom')
            ->assertDontSee('Delivered item');
    }

    public function test_guests_cannot_read_run_history(): void
    {
        $project = Project::factory()->create();
        $run = ProjectRun::factory()->for($project)->create();

        $this->get(route('project-runs.index', [$project->organization, $project]))
            ->assertRedirect(route('session.create'));

        $this->get(route('project-runs.show', [$project->organization, $project, $run]))
            ->assertRedirect(route('session.create'));
    }

    /**
     * @return array{User, Organization}
     */
    private function memberHolding(Role $role): array
    {
        $user = User::factory()->create();

        return [$user, Organization::factory()->withMember($user, $role)->create()];
    }
}
