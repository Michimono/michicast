<?php

namespace Tests\Feature;

use App\Enums\ProjectStatus;
use App\Enums\QuotaMetric;
use App\Enums\Role;
use App\Enums\RunStatus;
use App\Models\Broadcast;
use App\Models\BroadcastRun;
use App\Models\ContentDelivery;
use App\Models\ContentItem;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\OrganizationQuotaOverride;
use App\Models\Project;
use App\Models\ProjectRun;
use App\Models\Source;
use App\Models\User;
use App\Support\DestinationProviderRegistry;
use App\Support\DispatchDueProjects;
use App\Support\ExecutingProjectRunner;
use App\Support\RecordingDestinationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class QuotaExceededRunTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_run_at_the_fetch_limit_records_out_of_quota_without_http_or_failures(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00', 'UTC'));
        Http::preventStrayRequests();
        $log = Log::spy();

        [$organization, $project, $broadcast] = $this->rssProject();

        OrganizationQuotaOverride::query()->create([
            'organization_id' => $organization->getKey(),
            'metric' => QuotaMetric::SourceFetches,
            'limit' => 0,
        ]);

        $broadcast->forceFill(['consecutive_failures' => 2])->save();

        app(ExecutingProjectRunner::class)->run($project);

        $run = ProjectRun::query()->where('project_id', $project->getKey())->sole();
        $broadcastRun = BroadcastRun::query()->where('project_run_id', $run->getKey())->sole();

        $this->assertSame(RunStatus::QuotaExceeded, $run->status);
        $this->assertSame(RunStatus::QuotaExceeded, $broadcastRun->status);
        $this->assertSame(
            'Daily source fetch limit reached (0 of 0). Resets at 00:00 UTC.',
            $broadcastRun->error_detail,
        );
        $this->assertSame(2, $broadcast->fresh()->consecutive_failures);
        $this->assertTrue($broadcast->fresh()->enabled);
        $this->assertSame(0, ContentDelivery::query()->count());

        $log->shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($organization, $project, $broadcast): bool {
            return $message === 'quota.exceeded'
                && $context['organization_id'] == $organization->getKey()
                && $context['project_id'] == $project->getKey()
                && $context['broadcast_id'] == $broadcast->getKey()
                && $context['metric'] === QuotaMetric::SourceFetches->value;
        })->once();
    }

    public function test_deliveries_stop_at_the_limit_and_resume_next_period_without_double_posting(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00', 'UTC'));

        [$organization, $project] = $this->fakeProjectWithItems(3);
        $recording = $this->recordingDestination();

        OrganizationQuotaOverride::query()->create([
            'organization_id' => $organization->getKey(),
            'metric' => QuotaMetric::Deliveries,
            'limit' => 2,
        ]);

        app(ExecutingProjectRunner::class)->run($project);

        $run = ProjectRun::query()->where('project_id', $project->getKey())->sole();
        $this->assertSame(RunStatus::QuotaExceeded, $run->status);
        $this->assertSame(2, $run->items_delivered);
        $this->assertSame(2, count($recording->published()));
        $this->assertSame(2, ContentDelivery::query()->where('status', 'delivered')->count());
        $this->assertSame(0, ContentDelivery::query()->where('status', 'failed')->count());
        $this->assertSame(2, ContentDelivery::query()->count());

        $broadcast = $project->broadcasts()->first();
        $this->assertSame(0, $broadcast->fresh()->consecutive_failures);

        $this->travelTo(Carbon::parse('2026-10-07 00:01:00', 'UTC'));

        app(ExecutingProjectRunner::class)->run($project->fresh());

        $this->assertSame(3, count($recording->published()));
        $this->assertSame(3, ContentDelivery::query()->where('status', 'delivered')->count());

        $second = ProjectRun::query()
            ->where('project_id', $project->getKey())
            ->orderByDesc('id')
            ->first();
        $this->assertSame(RunStatus::Completed, $second->status);
        $this->assertSame(1, $second->items_delivered);
    }

    public function test_activity_page_shows_out_of_quota_and_the_reason(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->for($project)->create();
        $destination = Destination::factory()->for($project)->create();
        $broadcast = Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
        ]);

        $run = ProjectRun::factory()->for($project)->create([
            'status' => RunStatus::QuotaExceeded,
        ]);
        BroadcastRun::factory()->create([
            'project_run_id' => $run->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'status' => RunStatus::QuotaExceeded,
            'error_detail' => 'Daily source fetch limit reached (500 of 500). Resets at 00:00 UTC.',
        ]);

        $this->actingAs($user)
            ->get(route('broadcast-runs.show', [$organization, $project, $run]))
            ->assertOk()
            ->assertSee('Out of quota')
            ->assertSee('Daily source fetch limit reached (500 of 500). Resets at 00:00 UTC.');
    }

    public function test_interactive_actions_over_quota_return_readable_responses(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00', 'UTC'));

        [$user, $organization, $project, $broadcast, $source] = $this->interactiveFixtures();

        OrganizationQuotaOverride::query()->create([
            'organization_id' => $organization->getKey(),
            'metric' => QuotaMetric::SourceFetches,
            'limit' => 0,
        ]);

        $expected = 'Daily source fetch limit reached (0 of 0). Resets at 00:00 UTC.';

        $this->actingAs($user)
            ->post(route('content-previews.store', [$organization, $broadcast]))
            ->assertOk()
            ->assertSee($expected);

        $this->actingAs($user)
            ->post(route('source-connection-tests.store', [$organization, $source]), [
                'name' => $source->name,
                'config' => ['feed_url' => 'https://example.com/feed.xml'],
            ])
            ->assertOk()
            ->assertSee($expected);

        $this->actingAs($user)
            ->post(route('broadcast-source-previews.store', [$organization, $project]), [
                'name' => 'Preview source',
                'type' => 'fake',
                'config' => [
                    'feed_url' => 'https://example.com/feed.xml',
                    'items' => [],
                ],
            ])
            ->assertOk()
            ->assertSee($expected);

        $this->actingAs($user)
            ->post(route('broadcast-dry-runs.store', [$organization, $project]))
            ->assertRedirect(route('broadcasts.edit', [$organization, $project]))
            ->assertSessionHas('error', $expected);

        $this->actingAs($user)
            ->post(route('immediate-broadcast-runs.store', [$organization, $project]))
            ->assertRedirect(route('broadcasts.show', [$organization, $project]))
            ->assertSessionHas('error', $expected);

        // Seed one item for Send latest, still over fetch quota for any path that fetches;
        // Send latest uses publishSingleItem (delivery quota). Cap deliveries instead via a fresh org state.
    }

    public function test_send_latest_over_delivery_quota_returns_a_readable_error(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00', 'UTC'));

        [$user, $organization, $project] = $this->projectReadyForSendLatest();
        $this->recordingDestination();

        OrganizationQuotaOverride::query()->create([
            'organization_id' => $organization->getKey(),
            'metric' => QuotaMetric::Deliveries,
            'limit' => 0,
        ]);

        $expected = 'Daily delivery limit reached (0 of 0). Resets at 00:00 UTC.';

        $this->actingAs($user)
            ->post(route('broadcast-send-latests.store', [$organization, $project]))
            ->assertRedirect(route('broadcasts.edit', [$organization, $project]))
            ->assertSessionHas('error', $expected);
    }

    public function test_next_run_at_advances_normally_after_quota_stop(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        [$organization, $project] = $this->fakeProjectWithItems(1);
        $project->forceFill([
            'status' => ProjectStatus::Active,
            'schedule' => [
                'frequency' => 'daily',
                'time' => '09:00',
            ],
            'next_run_at' => Carbon::parse('2026-03-15 09:00:00', 'UTC'),
        ])->save();

        OrganizationQuotaOverride::query()->create([
            'organization_id' => $organization->getKey(),
            'metric' => QuotaMetric::SourceFetches,
            'limit' => 0,
        ]);

        app(DispatchDueProjects::class)->handle();

        $project->refresh();
        $this->assertNull($project->claimed_at);
        $this->assertTrue($project->next_run_at->equalTo(Carbon::parse('2026-03-16 09:00:00', 'UTC')));

        $run = ProjectRun::query()->where('project_id', $project->getKey())->sole();
        $this->assertSame(RunStatus::QuotaExceeded, $run->status);
    }

    /**
     * @return array{Organization, Project, Broadcast}
     */
    private function rssProject(): array
    {
        $organization = Organization::factory()->create(['plan' => 'free']);
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'type' => 'rss',
            'config' => ['feed_url' => 'https://example.com/feed.xml'],
        ]);
        $destination = Destination::factory()->create([
            'project_id' => $project->getKey(),
            'type' => 'recording',
        ]);
        $broadcast = Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'enabled' => true,
            'created_at' => Carbon::parse('2026-03-01 00:00:00', 'UTC'),
            'transform' => ['template' => '{{title}}'],
        ]);

        return [$organization, $project, $broadcast];
    }

    /**
     * @return array{Organization, Project}
     */
    private function fakeProjectWithItems(int $count): array
    {
        $organization = Organization::factory()->create(['plan' => 'free']);
        $project = Project::factory()->for($organization)->create();

        $items = [];
        for ($i = 1; $i <= $count; $i++) {
            $items[] = [
                'external_id' => "item-{$i}",
                'title' => "Title {$i}",
                'body' => "Body {$i}",
                'canonical_url' => "https://example.com/{$i}",
                'published_at' => '2026-10-06 09:00:00',
            ];
        }

        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => $items,
            ],
        ]);
        $destination = Destination::factory()->create([
            'project_id' => $project->getKey(),
            'type' => 'recording',
        ]);
        Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'enabled' => true,
            'created_at' => Carbon::parse('2026-03-01 00:00:00', 'UTC'),
            'transform' => ['template' => '{{title}}'],
        ]);

        return [$organization, $project];
    }

    /**
     * @return array{User, Organization, Project, Broadcast, Source}
     */
    private function interactiveFixtures(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, Role::Editor)->create(['plan' => 'free']);
        $project = Project::factory()->for($organization)->create([
            'status' => ProjectStatus::Active,
        ]);
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [[
                    'external_id' => 'item-1',
                    'title' => 'Sample',
                    'canonical_url' => 'https://example.com/1',
                    'published_at' => '2026-10-06 09:00:00',
                ]],
            ],
        ]);
        $destination = Destination::factory()->create([
            'project_id' => $project->getKey(),
            'type' => 'recording',
        ]);
        $broadcast = Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'enabled' => true,
            'created_at' => Carbon::parse('2026-03-01 00:00:00', 'UTC'),
            'transform' => ['template' => '{{title}}'],
        ]);

        return [$user, $organization, $project, $broadcast, $source];
    }

    /**
     * @return array{User, Organization, Project}
     */
    private function projectReadyForSendLatest(): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, Role::Editor)->create(['plan' => 'free']);
        $project = Project::factory()->for($organization)->create([
            'status' => ProjectStatus::Active,
        ]);
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [],
            ],
        ]);
        $destination = Destination::factory()->create([
            'project_id' => $project->getKey(),
            'type' => 'recording',
        ]);
        Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'enabled' => true,
            'created_at' => Carbon::parse('2026-03-01 00:00:00', 'UTC'),
            'transform' => ['template' => '{{title}}'],
        ]);

        ContentItem::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'title' => 'Latest item',
            'canonical_url' => 'https://example.com/latest',
            'published_at' => Carbon::parse('2026-10-06 09:00:00', 'UTC'),
            'discovered_at' => Carbon::parse('2026-10-06 09:00:00', 'UTC'),
        ]);

        return [$user, $organization, $project];
    }

    /**
     * @return array{User, Organization}
     */
    private function memberHolding(Role $role): array
    {
        $user = User::factory()->create();
        $organization = Organization::factory()->withMember($user, $role)->create();

        return [$user, $organization];
    }

    private function recordingDestination(): RecordingDestinationProvider
    {
        $provider = $this->app->make(DestinationProviderRegistry::class)->get('recording');
        $this->assertInstanceOf(RecordingDestinationProvider::class, $provider);

        return $provider;
    }
}
