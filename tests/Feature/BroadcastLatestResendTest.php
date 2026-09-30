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
use App\Models\Source;
use App\Models\User;
use App\Support\DestinationProviderRegistry;
use App\Support\ExecutingProjectRunner;
use App\Support\RecordingDestinationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BroadcastLatestResendTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_page_shows_ops_and_sidebar_link(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->active()->create(['name' => 'Relay']);

        $html = $this->actingAs($user)
            ->get(route('broadcasts.edit', [$organization, $project]))
            ->assertOk()
            ->assertSee('General')
            ->assertSee('Operations')
            ->assertSee('Dry run')
            ->assertSee('Allow resend of latest')
            ->assertSee('Send latest')
            ->assertSee(route('broadcast-dry-runs.store', [$organization, $project]), false)
            ->assertSee(route('broadcast-latest-resends.store', [$organization, $project]), false)
            ->assertSee(route('broadcast-send-latests.store', [$organization, $project]), false)
            ->assertDontSee('Edit broadcast')
            ->getContent();

        $this->assertStringContainsString('>Settings</span>', $html);
        $this->assertStringContainsString(route('broadcasts.edit', [$organization, $project]), $html);
    }

    public function test_dashboard_header_is_settings_run_now_and_pause_only(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->active()->create();

        $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->assertSee('Settings')
            ->assertSee('Run now')
            ->assertSee('Pause')
            ->assertDontSee('>Edit</', false)
            ->assertDontSee('Dry run')
            ->assertDontSee('All activity')
            ->assertDontSee('Play')
            ->assertSee(route('broadcasts.edit', [$organization, $project]), false);
    }

    public function test_overview_card_is_highlighted_with_rainbow_ears_not_pipeline(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $html = $this->actingAs($user)
            ->get(route('broadcasts.show', [$organization, $project]))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('house-edge', $html);
        $this->assertStringContainsString('viewBox="0 0 64 10"', $html);
        $this->assertStringContainsString('linearGradient', $html);
        $this->assertStringContainsString('var(--color-house-1)', $html);
        $this->assertStringContainsString('var(--color-house-2)', $html);
        $this->assertStringContainsString('data-pipeline-empty="source"', $html);

        // Highlight + rainbow ears belong on the Overview card, before the pipeline Source card - not on Source/Mapping.
        $this->assertMatchesRegularExpression(
            '/house-edge[\s\S]+linearGradient[\s\S]+Overview[\s\S]+data-mapping-pipeline[\s\S]+data-pipeline-empty="source"/',
            $html
        );
        $this->assertSame(1, substr_count($html, 'house-edge'));
        $this->assertSame(1, substr_count($html, 'viewBox="0 0 64 10"'));
        $this->assertDoesNotMatchRegularExpression(
            '/data-mapping-pipeline[\s\S]*house-edge/',
            $html
        );
    }

    public function test_allow_resend_of_latest_clears_delivery_without_publishing(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 12:00:00', 'UTC'));

        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->active()->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [[
                    'external_id' => 'latest-1',
                    'title' => 'Latest piece',
                    'canonical_url' => 'https://example.com/latest-1',
                    'published_at' => '2026-03-15T11:00:00Z',
                ]],
            ],
        ]);
        $destination = Destination::factory()->create(['project_id' => $project->getKey()]);
        Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'transform' => ['template' => '{{title}}'],
            'created_at' => Carbon::parse('2026-03-01 00:00:00', 'UTC'),
        ]);

        $recording = $this->recording();
        $this->app->make(ExecutingProjectRunner::class)->run($project);

        $this->assertCount(1, $recording->published());
        $this->assertSame(1, ContentDelivery::query()->count());
        $item = ContentItem::query()->sole();
        $this->assertSame(ContentItemLifecycle::Delivered, $item->lifecycle);

        $this->actingAs($user)
            ->from(route('broadcasts.edit', [$organization, $project]))
            ->post(route('broadcast-latest-resends.store', [$organization, $project]))
            ->assertRedirect(route('broadcasts.edit', [$organization, $project]))
            ->assertSessionHas('success');

        $this->assertSame(0, ContentDelivery::query()->count());
        $this->assertSame(ContentItemLifecycle::Normalized, $item->fresh()->lifecycle);
        $this->assertCount(1, $recording->published());

        $this->app->make(ExecutingProjectRunner::class)->run($project);

        $this->assertCount(2, $recording->published());
        $this->assertSame(1, ContentDelivery::query()->where('status', DeliveryStatus::Delivered)->count());
    }

    public function test_allow_resend_with_no_items_explains_itself(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->active()->create();

        $this->actingAs($user)
            ->post(route('broadcast-latest-resends.store', [$organization, $project]))
            ->assertRedirect(route('broadcasts.edit', [$organization, $project]))
            ->assertSessionHas('error', 'No received source item to allow a resend for.');
    }

    public function test_a_viewer_cannot_allow_resend(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $project = Project::factory()->for($organization)->active()->create();

        $this->actingAs($user)
            ->get(route('broadcasts.edit', [$organization, $project]))
            ->assertForbidden();

        $this->actingAs($user)
            ->post(route('broadcast-latest-resends.store', [$organization, $project]))
            ->assertForbidden();
    }

    public function test_only_the_latest_item_is_cleared(): void
    {
        $this->travelTo(Carbon::parse('2026-03-15 12:00:00', 'UTC'));

        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->active()->create();
        $source = Source::factory()->for($project)->create();
        $destination = Destination::factory()->for($project)->create();
        $broadcast = Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
        ]);

        $older = ContentItem::factory()->for($project)->forSource((int) $source->getKey())->create([
            'title' => 'Older',
            'discovered_at' => now()->subHour(),
            'lifecycle' => ContentItemLifecycle::Delivered,
        ]);
        $newer = ContentItem::factory()->for($project)->forSource((int) $source->getKey())->create([
            'title' => 'Newer',
            'discovered_at' => now(),
            'lifecycle' => ContentItemLifecycle::Delivered,
        ]);

        ContentDelivery::factory()->create([
            'content_item_id' => $older->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'destination_id' => $destination->getKey(),
            'status' => DeliveryStatus::Delivered,
        ]);
        ContentDelivery::factory()->create([
            'content_item_id' => $newer->getKey(),
            'broadcast_id' => $broadcast->getKey(),
            'destination_id' => $destination->getKey(),
            'status' => DeliveryStatus::Delivered,
        ]);

        $this->actingAs($user)
            ->post(route('broadcast-latest-resends.store', [$organization, $project]))
            ->assertRedirect(route('broadcasts.edit', [$organization, $project]));

        $this->assertTrue(ContentDelivery::query()->where('content_item_id', $older->getKey())->exists());
        $this->assertFalse(ContentDelivery::query()->where('content_item_id', $newer->getKey())->exists());
        $this->assertSame(ContentItemLifecycle::Delivered, $older->fresh()->lifecycle);
        $this->assertSame(ContentItemLifecycle::Normalized, $newer->fresh()->lifecycle);
    }

    /**
     * @return array{User, Organization}
     */
    private function memberHolding(Role $role): array
    {
        $user = User::factory()->create();

        return [$user, Organization::factory()->withMember($user, $role)->create()];
    }

    private function recording(): RecordingDestinationProvider
    {
        $provider = $this->app->make(DestinationProviderRegistry::class)->get('recording');
        $this->assertInstanceOf(RecordingDestinationProvider::class, $provider);

        return $provider;
    }
}
