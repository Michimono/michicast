<?php

namespace Tests\Feature;

use App\Enums\ContentItemLifecycle;
use App\Enums\DeliveryStatus;
use App\Enums\Role;
use App\Models\Broadcast;
use App\Models\ContentDelivery;
use App\Models\ContentItem;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectRun;
use App\Models\Source;
use App\Models\User;
use App\Support\DestinationProviderRegistry;
use App\Support\ExecutingProjectRunner;
use App\Support\RecordingDestinationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ProjectDryRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_dry_run_reports_intended_publishes_without_sending(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        [$user, $organization, $project] = $this->projectReadyForDryRun();

        $recording = $this->recordingDestination();
        $before = count($recording->published());

        $this->actingAs($user)
            ->post(route('broadcast-dry-runs.store', [$organization, $project]))
            ->assertRedirect();

        $run = ProjectRun::query()->where('project_id', $project->getKey())->sole();
        $this->assertTrue($run->is_dry);
        $this->assertSame(2, $run->items_found);
        $this->assertSame(1, $run->items_skipped);
        $this->assertSame(1, $run->items_delivered);
        $this->assertSame(0, $run->items_failed);

        $broadcastRun = $run->broadcastRuns()->sole();
        $this->assertIsArray($broadcastRun->dry_report);
        $this->assertCount(2, $broadcastRun->dry_report);
        $this->assertTrue(collect($broadcastRun->dry_report)->contains(
            fn (array $entry): bool => ($entry['outcome'] ?? null) === 'publish'
                && str_contains((string) ($entry['rendered'] ?? ''), 'Pokemon drop')
        ));

        $this->assertSame($before, count($recording->published()));
        $this->assertSame(0, ContentDelivery::query()->count());
        $this->assertSame(0, ContentItem::query()->where('lifecycle', ContentItemLifecycle::Delivered)->count());
        $this->assertSame(0, ContentItem::query()->where('lifecycle', ContentItemLifecycle::Eligible)->count());
    }

    public function test_a_dry_run_is_recorded_as_dry_in_history(): void
    {
        [$user, $organization, $project] = $this->projectReadyForDryRun();

        $this->actingAs($user)
            ->post(route('broadcast-dry-runs.store', [$organization, $project]))
            ->assertRedirect();

        $run = ProjectRun::query()->where('project_id', $project->getKey())->sole();

        $this->actingAs($user)
            ->get(route('project-runs.index', [$organization, $project]))
            ->assertOk()
            ->assertSee('Dry run');

        $this->actingAs($user)
            ->get(route('project-runs.show', [$organization, $project, $run]))
            ->assertOk()
            ->assertSee('dry run')
            ->assertSee('Would publish')
            ->assertSee('Would skip');
    }

    public function test_runner_dry_run_never_calls_the_destination(): void
    {
        $project = Project::factory()->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [[
                    'external_id' => 'item-1',
                    'title' => 'Hello',
                    'canonical_url' => 'https://example.com/1',
                ]],
            ],
        ]);
        $destination = Destination::factory()->create(['project_id' => $project->getKey()]);
        Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'transform' => ['template' => '{{title}}'],
        ]);

        $recording = $this->recordingDestination();
        $before = count($recording->published());

        $run = $this->app->make(ExecutingProjectRunner::class)->dryRun($project);

        $this->assertTrue($run->is_dry);
        $this->assertSame(1, $run->items_delivered);
        $this->assertSame($before, count($recording->published()));
        $this->assertSame(0, ContentDelivery::query()->where('status', DeliveryStatus::Delivered)->count());
    }

    public function test_a_viewer_cannot_dry_run(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $project = Project::factory()->for($organization)->active()->create();

        $this->actingAs($user)
            ->get(route('projects.show', [$organization, $project]))
            ->assertOk()
            ->assertDontSee('Dry run');

        $this->actingAs($user)
            ->post(route('broadcast-dry-runs.store', [$organization, $project]))
            ->assertForbidden();
    }

    public function test_dry_run_is_rate_limited(): void
    {
        [$user, $organization, $project] = $this->projectReadyForDryRun();

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->actingAs($user)
                ->post(route('broadcast-dry-runs.store', [$organization, $project]))
                ->assertRedirect();
        }

        $this->actingAs($user)
            ->post(route('broadcast-dry-runs.store', [$organization, $project]))
            ->assertTooManyRequests();
    }

    public function test_an_editor_sees_dry_run_next_to_run_now(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->active()->create();

        $this->actingAs($user)
            ->get(route('projects.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Dry run')
            ->assertSee(route('broadcast-dry-runs.store', [$organization, $project]), false);
    }

    /**
     * @return array{User, Organization, Project}
     */
    private function projectReadyForDryRun(): array
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->active()->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [
                    [
                        'external_id' => 'keep-1',
                        'title' => 'Pokemon drop',
                        'body' => 'A keepable item.',
                        'canonical_url' => 'https://example.com/posts/keep-1',
                        'published_at' => '2026-03-15 09:00:00',
                    ],
                    [
                        'external_id' => 'skip-1',
                        'title' => 'Unrelated news',
                        'body' => 'Filtered out.',
                        'canonical_url' => 'https://example.com/posts/skip-1',
                        'published_at' => '2026-03-14 09:00:00',
                    ],
                ],
            ],
        ]);
        $destination = Destination::factory()->create(['project_id' => $project->getKey()]);
        Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'filter' => [
                'rules' => [['type' => 'contains', 'field' => 'title', 'value' => 'pokemon']],
            ],
            'transform' => [
                'template' => 'News: {{title}}',
            ],
            'created_at' => Carbon::parse('2026-03-01 00:00:00', 'UTC'),
        ]);

        return [$user, $organization, $project];
    }

    private function recordingDestination(): RecordingDestinationProvider
    {
        $provider = $this->app->make(DestinationProviderRegistry::class)->get('recording');
        $this->assertInstanceOf(RecordingDestinationProvider::class, $provider);

        return $provider;
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
