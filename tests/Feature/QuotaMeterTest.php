<?php

namespace Tests\Feature;

use App\Enums\QuotaMetric;
use App\Enums\Role;
use App\Models\Broadcast;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\OrganizationQuotaOverride;
use App\Models\OrganizationUsage;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Support\DestinationProviderRegistry;
use App\Support\ExecutingProjectRunner;
use App\Support\QuotaMeter;
use App\Support\RecordingDestinationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class QuotaMeterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These tests cover daily counting; plans default to monthly (#196).
        $daily = ['source_fetches' => 'daily', 'deliveries' => 'daily'];
        config(['michicast.quotas.plan_periods' => ['free' => $daily, 'unlimited' => $daily]]);
    }

    public function test_consume_increments_and_refuses_at_the_limit(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00', 'UTC'));

        $organization = Organization::factory()->create(['plan' => 'free']);

        OrganizationQuotaOverride::query()->create([
            'organization_id' => $organization->getKey(),
            'metric' => QuotaMetric::SourceFetches,
            'limit' => 2,
            'note' => 'test cap',
        ]);

        $meter = app(QuotaMeter::class);

        $first = $meter->consume($organization, QuotaMetric::SourceFetches);
        $second = $meter->consume($organization, QuotaMetric::SourceFetches);
        $third = $meter->consume($organization, QuotaMetric::SourceFetches);

        $this->assertTrue($first->allowed);
        $this->assertSame(1, $first->used);
        $this->assertSame(2, $first->limit);

        $this->assertTrue($second->allowed);
        $this->assertSame(2, $second->used);

        $this->assertFalse($third->allowed);
        $this->assertSame(2, $third->used);
        $this->assertSame(2, $meter->used($organization, QuotaMetric::SourceFetches));
        $this->assertSame(0, $meter->remaining($organization, QuotaMetric::SourceFetches));
        $this->assertTrue($third->resetsAt->equalTo(Carbon::parse('2026-10-07 00:00:00', 'UTC')));
    }

    public function test_a_run_consumes_one_fetch_per_source_and_one_delivery_per_publish(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        [$organization, $project] = $this->projectWithTwoSources();
        $this->recordingDestination();

        app(ExecutingProjectRunner::class)->run($project);

        $meter = app(QuotaMeter::class);

        $this->assertSame(2, $meter->used($organization, QuotaMetric::SourceFetches));
        $this->assertSame(2, $meter->used($organization, QuotaMetric::Deliveries));
    }

    public function test_dry_runs_consume_fetches_but_not_deliveries(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        [$organization, $project] = $this->projectWithTwoSources();
        $this->recordingDestination();

        app(ExecutingProjectRunner::class)->dryRun($project);

        $meter = app(QuotaMeter::class);

        $this->assertSame(2, $meter->used($organization, QuotaMetric::SourceFetches));
        $this->assertSame(0, $meter->used($organization, QuotaMetric::Deliveries));
    }

    public function test_preview_and_connection_test_consume_a_fetch(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 10:00:00', 'UTC'));

        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [[
                    'external_id' => 'item-1',
                    'title' => 'Sample headline',
                    'canonical_url' => 'https://example.com/1',
                    'published_at' => '2026-03-15 09:00:00',
                ]],
            ],
        ]);
        $destination = Destination::factory()->create(['project_id' => $project->getKey()]);
        $broadcast = Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'created_at' => Carbon::parse('2026-03-01 00:00:00', 'UTC'),
        ]);

        $meter = app(QuotaMeter::class);
        $this->assertSame(0, $meter->used($organization, QuotaMetric::SourceFetches));

        app(ExecutingProjectRunner::class)->preview($broadcast);
        $this->assertSame(1, $meter->used($organization, QuotaMetric::SourceFetches));

        $this->actingAs($user)
            ->post(route('source-connection-tests.store', [$organization, $source]), [
                'name' => $source->name,
                'config' => ['feed_url' => 'https://example.com/feed.xml'],
            ])
            ->assertOk();

        $this->assertSame(2, $meter->used($organization, QuotaMetric::SourceFetches));

        $this->actingAs($user)
            ->post(route('broadcast-source-previews.store', [$organization, $project]), [
                'name' => 'Preview source',
                'type' => 'fake',
                'config' => [
                    'feed_url' => 'https://example.com/feed.xml',
                    'items' => [[
                        'external_id' => 'preview-1',
                        'title' => 'Preview item',
                        'canonical_url' => 'https://example.com/preview-1',
                    ]],
                ],
            ])
            ->assertOk();

        $this->assertSame(3, $meter->used($organization, QuotaMetric::SourceFetches));
    }

    public function test_two_organizations_usage_is_independent(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00', 'UTC'));

        $alpha = Organization::factory()->create(['plan' => 'free']);
        $beta = Organization::factory()->create(['plan' => 'free']);

        foreach ([$alpha, $beta] as $organization) {
            OrganizationQuotaOverride::query()->create([
                'organization_id' => $organization->getKey(),
                'metric' => QuotaMetric::Deliveries,
                'limit' => 1,
            ]);
        }

        $meter = app(QuotaMeter::class);

        $this->assertTrue($meter->consume($alpha, QuotaMetric::Deliveries)->allowed);
        $this->assertFalse($meter->consume($alpha, QuotaMetric::Deliveries)->allowed);

        $this->assertTrue($meter->consume($beta, QuotaMetric::Deliveries)->allowed);
        $this->assertSame(1, $meter->used($alpha, QuotaMetric::Deliveries));
        $this->assertSame(1, $meter->used($beta, QuotaMetric::Deliveries));
    }

    public function test_the_period_rolls_over_at_utc_midnight(): void
    {
        $organization = Organization::factory()->create(['plan' => 'free']);

        OrganizationQuotaOverride::query()->create([
            'organization_id' => $organization->getKey(),
            'metric' => QuotaMetric::SourceFetches,
            'limit' => 1,
        ]);

        $meter = app(QuotaMeter::class);

        $this->travelTo(Carbon::parse('2026-10-06 23:59:00', 'UTC'));
        $before = $meter->consume($organization, QuotaMetric::SourceFetches);
        $this->assertTrue($before->allowed);
        $this->assertSame(1, $meter->used($organization, QuotaMetric::SourceFetches));
        $this->assertFalse($meter->consume($organization, QuotaMetric::SourceFetches)->allowed);

        $this->travelTo(Carbon::parse('2026-10-07 00:00:00', 'UTC'));
        $after = $meter->consume($organization, QuotaMetric::SourceFetches);
        $this->assertTrue($after->allowed);
        $this->assertSame(1, $after->used);
        $this->assertSame(1, $meter->used($organization, QuotaMetric::SourceFetches));

        $this->assertSame(2, OrganizationUsage::query()->where('organization_id', $organization->getKey())->count());
    }

    public function test_unlimited_limit_still_tracks_usage(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00', 'UTC'));

        $organization = Organization::factory()->create(['plan' => 'unlimited']);
        $meter = app(QuotaMeter::class);

        $decision = $meter->consume($organization, QuotaMetric::SourceFetches);
        $this->assertTrue($decision->allowed);
        $this->assertNull($decision->limit);
        $this->assertSame(1, $decision->used);
        $this->assertNull($meter->remaining($organization, QuotaMetric::SourceFetches));
    }

    public function test_usage_rows_older_than_ninety_days_are_pruned(): void
    {
        $this->travelTo(Carbon::parse('2026-10-06 12:00:00', 'UTC'));

        $organization = Organization::factory()->create();

        OrganizationUsage::query()->create([
            'organization_id' => $organization->getKey(),
            'metric' => QuotaMetric::SourceFetches,
            'period_start' => '2026-07-01',
            'used' => 5,
        ]);
        OrganizationUsage::query()->create([
            'organization_id' => $organization->getKey(),
            'metric' => QuotaMetric::SourceFetches,
            'period_start' => '2026-09-01',
            'used' => 3,
        ]);

        $this->artisan('usage:prune')->assertSuccessful();

        $this->assertSame(1, OrganizationUsage::query()->count());
        $this->assertSame('2026-09-01', OrganizationUsage::query()->sole()->period_start->toDateString());
    }

    /**
     * @return array{Organization, Project}
     */
    private function projectWithTwoSources(): array
    {
        $organization = Organization::factory()->create(['plan' => 'free']);
        $project = Project::factory()->for($organization)->create();

        foreach (['one', 'two'] as $suffix) {
            $source = Source::factory()->create([
                'project_id' => $project->getKey(),
                'config' => [
                    'feed_url' => "https://example.com/feed-{$suffix}.xml",
                    'items' => [[
                        'external_id' => "item-{$suffix}",
                        'title' => "Title {$suffix}",
                        'body' => "Body {$suffix}",
                        'canonical_url' => "https://example.com/{$suffix}",
                        'published_at' => '2026-03-15 09:00:00',
                    ]],
                ],
            ]);
            $destination = Destination::factory()->create(['project_id' => $project->getKey()]);
            Broadcast::factory()->create([
                'project_id' => $project->getKey(),
                'source_id' => $source->getKey(),
                'destination_id' => $destination->getKey(),
                'created_at' => Carbon::parse('2026-03-01 00:00:00', 'UTC'),
                'transform' => ['template' => '{{title}}'],
            ]);
        }

        return [$organization, $project];
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
