<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Broadcast;
use App\Models\ContentDelivery;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectRun;
use App\Models\Source;
use App\Models\User;
use App\Support\DestinationDefinition;
use App\Support\DestinationProviderRegistry;
use App\Support\ExecutingProjectRunner;
use App\Support\PreparedMessage;
use App\Support\RecordingDestinationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The Activity log (testing) destination (internal type `recording`, #202): offered by
 * default everywhere, switchable off for new destinations while existing ones
 * keep working; it keeps each message in the delivery history. Also covers the
 * create form's instant type switch.
 */
class TestDestinationVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_flag_defaults_on_in_the_testing_environment(): void
    {
        $this->assertTrue(config('michicast.test_destinations_enabled'));
        $this->assertContains('recording', app(DestinationProviderRegistry::class)->selectableTypes());
    }

    public function test_recording_is_hidden_from_the_create_form_in_production(): void
    {
        $this->productionLike();
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $registry = app(DestinationProviderRegistry::class);
        $this->assertSame(['telegram'], $registry->selectableTypes());
        $this->assertSame('telegram', $registry->defaultType());
        $this->assertTrue($registry->has('recording'));

        $this->actingAs($user)
            ->get(route('broadcast-destinations.create', [$organization, $project]))
            ->assertOk()
            ->assertSee('value="telegram"', false)
            ->assertDontSee('value="recording"', false)
            ->assertDontSee('Activity log (testing)');

        // Asking for it explicitly falls back to the default selectable type.
        $this->actingAs($user)
            ->get(route('broadcast-destinations.create', [$organization, $project, 'type' => 'recording']))
            ->assertOk()
            ->assertDontSee('Activity log (testing)');
    }

    public function test_recording_is_offered_in_local(): void
    {
        config(['michicast.test_destinations_enabled' => true]);
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->get(route('broadcast-destinations.create', [$organization, $project, 'type' => 'recording']))
            ->assertOk()
            ->assertSee('Activity log (testing)');
    }

    public function test_store_rejects_recording_in_production(): void
    {
        $this->productionLike();
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->postJson(route('broadcast-destinations.store', [$organization, $project]), [
                'name' => 'Telegram test channel',
                'type' => 'recording',
                'config' => ['channel' => 'news'],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('type');

        $this->assertSame(0, $project->destinations()->count());
    }

    public function test_an_existing_recording_destination_still_renders_in_production(): void
    {
        $this->productionLike();
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $destination = Destination::factory()->create([
            'project_id' => $project->getKey(),
            'type' => 'recording',
            'name' => 'Telegram test channel',
            'config' => ['channel' => 'news'],
        ]);

        $this->actingAs($user)
            ->get(route('destinations.edit', [$organization, $destination]))
            ->assertOk()
            ->assertSee('Telegram test channel');
    }

    public function test_the_create_form_renders_every_type_so_the_switch_needs_no_reload(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $html = $this->actingAs($user)
            ->get(route('broadcast-destinations.create', [$organization, $project]))
            ->assertOk()
            ->assertSee('data-destination-type-switch', false)
            ->assertSee('data-destination-type-reload', false)
            ->assertSee('Activity log (testing)')
            ->getContent();

        $this->assertIsString($html);
        // Telegram is the default; its group is live, the Activity log group waits disabled.
        $this->assertMatchesRegularExpression('/data-destination-type-fields="telegram"\s*>/', $html);
        $this->assertMatchesRegularExpression('/data-destination-type-fields="recording"\s+hidden disabled/', $html);
        $this->assertStringContainsString('name="config[chat_id]"', $html);
        $this->assertStringContainsString('name="config[channel]"', $html);
        $this->assertMatchesRegularExpression('/name="type" value="telegram"\s+data-destination-type-input/', $html);
    }

    public function test_choosing_a_type_by_query_enables_that_group(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $html = $this->actingAs($user)
            ->get(route('broadcast-destinations.create', [$organization, $project, 'type' => 'recording']))
            ->getContent();

        $this->assertIsString($html);
        $this->assertMatchesRegularExpression('/data-destination-type-fields="recording"\s*>/', $html);
        $this->assertMatchesRegularExpression('/data-destination-type-fields="telegram"\s+hidden disabled/', $html);
    }

    public function test_the_activity_log_keeps_the_message_it_was_given(): void
    {
        $provider = new RecordingDestinationProvider;
        $result = $provider->publish(
            new DestinationDefinition(type: 'recording', config: ['channel' => 'news'], credentials: ['token' => 'x'], projectId: 1, destinationId: 1),
            new PreparedMessage('Hello Pokémon fans', 'https://example.com/post'),
        );

        $this->assertTrue($result->successful);
        $this->assertSame("Hello Pokémon fans\n\nhttps://example.com/post", $result->publishedMessage);
        $this->assertSame(['type' => 'recording', 'id' => 1, 'channel' => 'news'], $result->publishedPayload['destination']);
        $this->assertSame('https://example.com/post', $result->publishedPayload['message']['link'] ?? null);
        $this->assertArrayNotHasKey('token', $result->publishedPayload['destination']);
    }

    public function test_logged_messages_show_in_the_delivery_history(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $broadcast = Broadcast::factory()->create(['project_id' => $project->getKey()]);
        ContentDelivery::factory()->create([
            'broadcast_id' => $broadcast->getKey(),
            'destination_id' => $broadcast->destination_id,
            'published_message' => "Weekly news\n\nhttps://example.com/post",
            'published_payload' => ['message' => ['body' => 'Weekly <b>news</b>', 'link' => 'https://example.com/post']],
        ]);

        $this->actingAs($user)
            ->get(route('broadcast-deliveries.index', [$organization, $broadcast]))
            ->assertOk()
            ->assertSee('Logged message')
            ->assertSee('data-delivery-message', false)
            ->assertSee('Weekly news')
            ->assertSee('data-delivery-payload', false)
            ->assertSee('Sent payload')
            // Pretty-printed and escaped.
            ->assertSee('&quot;body&quot;: &quot;Weekly &lt;b&gt;news&lt;/b&gt;&quot;', false);
    }

    public function test_a_run_to_the_activity_log_shows_the_message_in_activity(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->due()->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [[
                    'external_id' => 'item-1',
                    'title' => 'Activity log item',
                    'canonical_url' => 'https://example.com/item-1',
                    'published_at' => now()->addHour()->toIso8601String(),
                ]],
            ],
        ]);
        $destination = Destination::factory()->create([
            'project_id' => $project->getKey(),
            'type' => 'recording',
            'config' => ['channel' => 'news'],
            'credentials' => ['token' => 'x'],
        ]);
        $broadcast = Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        $this->app->make(ExecutingProjectRunner::class)->run($project);

        $message = (string) $broadcast->deliveries()->sole()->published_message;
        $this->assertStringContainsString('Activity log item', $message);

        $payload = $broadcast->deliveries()->sole()->published_payload;
        $this->assertNotNull($payload);
        $this->assertSame('item-1', $payload['content_item']['external_id']);
        $this->assertSame($broadcast->getKey(), $payload['broadcast_id']);
        $this->assertSame('recording', $payload['destination']['type']);
        $this->assertStringContainsString('Activity log item', $payload['message']['body']);
        $this->assertStringNotContainsString('"token"', (string) json_encode($payload));

        $this->actingAs($user)
            ->get(route('broadcast-runs.show', [$organization, $project, ProjectRun::query()->sole()]))
            ->assertOk()
            ->assertSee('data-delivery-message', false)
            ->assertSee('Activity log item')
            ->assertSee('Sent payload')
            ->assertSee('&quot;external_id&quot;: &quot;item-1&quot;', false);
    }

    /**
     * @return array{User, Organization}
     */
    private function memberHolding(Role $role): array
    {
        $user = User::factory()->create();

        return [$user, Organization::factory()->withMember($user, $role)->create()];
    }

    private function productionLike(): void
    {
        // The flag is what production resolves to (APP_ENV is read when config loads).
        // Switching the app env itself would also turn on CSRF checks in tests.
        config(['michicast.test_destinations_enabled' => false]);
    }
}
