<?php

namespace Tests\Feature;

use App\Enums\ConfigurationFieldType;
use App\Enums\Role;
use App\Models\ContentItem;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Source;
use App\Models\User;
use App\Support\ConfigurationField;
use App\Support\ConnectionTestResult;
use App\Support\SourceDefinition;
use App\Support\SourceProvider;
use App\Support\SourceProviderCapabilities;
use App\Support\SourceProviderRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SourceConnectionTestTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_valid_source_reports_success_and_a_sample(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/old-feed.xml',
                'items' => [
                    [
                        'external_id' => 'item-1',
                        'title' => 'Sample headline',
                        'canonical_url' => 'https://example.com/1',
                    ],
                ],
            ],
        ]);

        $this->actingAs($user)
            ->from(route('sources.edit', [$organization, $source]))
            ->post(route('source-connection-tests.store', [$organization, $source]), [
                'name' => $source->name,
                'config' => [
                    'feed_url' => 'https://example.com/feed.xml',
                ],
            ])
            ->assertOk()
            ->assertSee('Sample headline')
            ->assertSee('Reached https://example.com/feed.xml');

        $source->refresh();

        $this->assertTrue($source->last_test_successful);
        $this->assertNotNull($source->last_tested_at);
        $this->assertStringContainsString('Sample headline', (string) $source->last_test_message);
        $this->assertSame('https://example.com/old-feed.xml', $source->config['feed_url']);
        $this->assertSame(0, ContentItem::query()->count());
    }

    public function test_an_invalid_source_reports_an_actionable_failure(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => ['feed_url' => 'https://example.com/feed.xml'],
        ]);

        $this->actingAs($user)
            ->post(route('source-connection-tests.store', [$organization, $source]), [
                'name' => $source->name,
                'config' => ['feed_url' => 'https://example.com/broken'],
            ])
            ->assertOk()
            ->assertSee('The fake source could not reach that feed URL.');

        $source->refresh();

        $this->assertFalse($source->last_test_successful);
        $this->assertSame('The fake source could not reach that feed URL.', $source->last_test_message);
    }

    public function test_the_action_is_hidden_when_the_provider_lacks_the_capability(): void
    {
        $this->app->make(SourceProviderRegistry::class)->register('mute', MuteSourceProvider::class);

        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'type' => 'mute',
            'config' => [],
        ]);

        $this->actingAs($user)
            ->get(route('sources.edit', [$organization, $source]))
            ->assertOk()
            ->assertDontSee('Test connection')
            ->assertDontSee(route('source-connection-tests.store', [$organization, $source]), false);
    }

    public function test_a_viewer_cannot_test_a_source(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->create(['project_id' => $project->getKey()]);

        $this->actingAs($user)
            ->post(route('source-connection-tests.store', [$organization, $source]), [
                'name' => $source->name,
                'config' => ['feed_url' => 'https://example.com/feed.xml'],
            ])
            ->assertForbidden();
    }

    public function test_source_connection_tests_are_rate_limited(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => ['feed_url' => 'https://example.com/feed.xml'],
        ]);

        $payload = [
            'name' => $source->name,
            'config' => ['feed_url' => 'https://example.com/feed.xml'],
        ];

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->actingAs($user)
                ->post(route('source-connection-tests.store', [$organization, $source]), $payload)
                ->assertOk();
        }

        $this->actingAs($user)
            ->post(route('source-connection-tests.store', [$organization, $source]), $payload)
            ->assertTooManyRequests();
    }

    public function test_a_test_result_message_does_not_leak_credentials(): void
    {
        $this->app->make(SourceProviderRegistry::class)->register('leaky', LeakySourceProvider::class);

        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'type' => 'leaky',
            'config' => ['feed_url' => 'https://example.com/feed.xml'],
        ]);

        $this->actingAs($user)
            ->post(route('source-connection-tests.store', [$organization, $source]), [
                'name' => $source->name,
                'config' => ['feed_url' => 'https://example.com/feed.xml'],
            ])
            ->assertOk()
            ->assertSee('[redacted]')
            ->assertDontSee('1234567890:ABCDEFGHIJKLMNOPQRSTUVWX');

        $this->assertStringContainsString('[redacted]', (string) $source->refresh()->last_test_message);
        $this->assertStringNotContainsString('1234567890:ABCDEFGHIJKLMNOPQRSTUVWX', (string) $source->last_test_message);
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

class MuteSourceProvider implements SourceProvider
{
    public function type(): string
    {
        return 'mute';
    }

    public function label(): string
    {
        return 'Mute source';
    }

    public function configurationRules(): array
    {
        return [];
    }

    public function configurationFields(): array
    {
        return [];
    }

    public function capabilities(): SourceProviderCapabilities
    {
        return new SourceProviderCapabilities(supportsConnectionTest: false);
    }

    public function testConnection(SourceDefinition $source): ConnectionTestResult
    {
        return ConnectionTestResult::unsupported();
    }

    public function fetch(SourceDefinition $source): array
    {
        return [];
    }
}

class LeakySourceProvider implements SourceProvider
{
    public function type(): string
    {
        return 'leaky';
    }

    public function label(): string
    {
        return 'Leaky source';
    }

    public function configurationRules(): array
    {
        return ['feed_url' => ['required', 'url']];
    }

    public function configurationFields(): array
    {
        return [
            new ConfigurationField(
                name: 'feed_url',
                label: 'Feed URL',
                type: ConfigurationFieldType::Url,
            ),
        ];
    }

    public function capabilities(): SourceProviderCapabilities
    {
        return new SourceProviderCapabilities(supportsConnectionTest: true);
    }

    public function testConnection(SourceDefinition $source): ConnectionTestResult
    {
        return ConnectionTestResult::failure('Rejected token 1234567890:ABCDEFGHIJKLMNOPQRSTUVWX');
    }

    public function fetch(SourceDefinition $source): array
    {
        return [];
    }
}
