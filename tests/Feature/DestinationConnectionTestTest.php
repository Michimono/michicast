<?php

namespace Tests\Feature;

use App\Enums\ConfigurationFieldType;
use App\Enums\Role;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Support\ConfigurationField;
use App\Support\ConnectionTestResult;
use App\Support\DeliveryResult;
use App\Support\DestinationDefinition;
use App\Support\DestinationProvider;
use App\Support\DestinationProviderCapabilities;
use App\Support\DestinationProviderRegistry;
use App\Support\PreparedMessage;
use App\Support\RecordingDestinationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DestinationConnectionTestTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_a_destination_test_produces_a_marked_message_and_reports_success(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $destination = Destination::factory()->create([
            'project_id' => $project->getKey(),
            'config' => ['channel' => 'updates'],
            'credentials' => ['token' => 'stored-secret'],
        ]);

        $recording = $this->recording();
        $before = count($recording->published());

        $this->actingAs($user)
            ->post(route('destination-connection-tests.store', [$organization, $destination]), [
                'name' => $destination->name,
                'config' => ['channel' => 'updates'],
                'credentials' => ['token' => ''],
            ])
            ->assertOk()
            ->assertSee('Sent a test message');

        $published = $recording->published();
        $this->assertCount($before + 1, $published);
        $this->assertStringContainsString('[Michicast test]', $published[$before]->body);

        $destination->refresh();

        $this->assertTrue($destination->last_test_successful);
        $this->assertNotNull($destination->last_tested_at);
        $this->assertSame('stored-secret', $destination->credentials['token']);
    }

    public function test_a_refused_destination_reports_an_actionable_failure(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $destination = Destination::factory()->create([
            'project_id' => $project->getKey(),
            'credentials' => [],
        ]);

        $this->actingAs($user)
            ->post(route('destination-connection-tests.store', [$organization, $destination]), [
                'name' => $destination->name,
                'config' => ['channel' => 'updates'],
                'credentials' => ['token' => ''],
            ])
            ->assertOk()
            ->assertSee('The recording destination has no token to authenticate with.');

        $destination->refresh();

        $this->assertFalse($destination->last_test_successful);
        $this->assertSame(
            'The recording destination has no token to authenticate with.',
            $destination->last_test_message,
        );
    }

    public function test_the_action_is_hidden_when_the_provider_lacks_the_capability(): void
    {
        $this->app->make(DestinationProviderRegistry::class)->register('mute', MuteDestinationProvider::class);

        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $destination = Destination::factory()->create([
            'project_id' => $project->getKey(),
            'type' => 'mute',
            'config' => [],
            'credentials' => [],
        ]);

        $this->actingAs($user)
            ->get(route('destinations.edit', [$organization, $destination]))
            ->assertOk()
            ->assertDontSee('Send test')
            ->assertDontSee(route('destination-connection-tests.store', [$organization, $destination]), false);
    }

    public function test_a_viewer_cannot_test_a_destination(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Viewer);
        $project = Project::factory()->for($organization)->create();
        $destination = Destination::factory()->create(['project_id' => $project->getKey()]);

        $this->actingAs($user)
            ->post(route('destination-connection-tests.store', [$organization, $destination]), [
                'name' => $destination->name,
                'config' => ['channel' => 'updates'],
                'credentials' => ['token' => 'secret'],
            ])
            ->assertForbidden();
    }

    public function test_destination_connection_tests_are_rate_limited(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $destination = Destination::factory()->create(['project_id' => $project->getKey()]);

        $payload = [
            'name' => $destination->name,
            'config' => ['channel' => 'updates'],
            'credentials' => ['token' => ''],
        ];

        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->actingAs($user)
                ->post(route('destination-connection-tests.store', [$organization, $destination]), $payload)
                ->assertOk();
        }

        $this->actingAs($user)
            ->post(route('destination-connection-tests.store', [$organization, $destination]), $payload)
            ->assertTooManyRequests();
    }

    public function test_a_test_result_message_does_not_leak_credentials(): void
    {
        $this->app->make(DestinationProviderRegistry::class)->register('leaky', LeakyDestinationProvider::class);

        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();
        $destination = Destination::factory()->create([
            'project_id' => $project->getKey(),
            'type' => 'leaky',
            'config' => ['channel' => 'updates'],
            'credentials' => ['token' => 'stored-secret'],
        ]);

        $this->actingAs($user)
            ->post(route('destination-connection-tests.store', [$organization, $destination]), [
                'name' => $destination->name,
                'config' => ['channel' => 'updates'],
                'credentials' => ['token' => ''],
            ])
            ->assertOk()
            ->assertSee('[redacted]')
            ->assertDontSee('abcdefghijklmnop');

        $this->assertStringContainsString('[redacted]', (string) $destination->refresh()->last_test_message);
    }

    private function recording(): RecordingDestinationProvider
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

class MuteDestinationProvider implements DestinationProvider
{
    public function type(): string
    {
        return 'mute';
    }

    public function label(): string
    {
        return 'Mute destination';
    }

    public function configurationRules(): array
    {
        return [];
    }

    public function configurationFields(): array
    {
        return [];
    }

    public function credentialRules(): array
    {
        return [];
    }

    public function credentialFields(): array
    {
        return [];
    }

    public function capabilities(): DestinationProviderCapabilities
    {
        return new DestinationProviderCapabilities(supportsConnectionTest: false);
    }

    public function testConnection(DestinationDefinition $destination): ConnectionTestResult
    {
        return ConnectionTestResult::unsupported();
    }

    public function publish(DestinationDefinition $destination, PreparedMessage $message): DeliveryResult
    {
        return DeliveryResult::success('mute');
    }
}

class LeakyDestinationProvider implements DestinationProvider
{
    public function type(): string
    {
        return 'leaky';
    }

    public function label(): string
    {
        return 'Leaky destination';
    }

    public function configurationRules(): array
    {
        return ['channel' => ['required', 'string']];
    }

    public function configurationFields(): array
    {
        return [
            new ConfigurationField(
                name: 'channel',
                label: 'Channel',
                type: ConfigurationFieldType::Text,
            ),
        ];
    }

    public function credentialRules(): array
    {
        return ['token' => ['required', 'string']];
    }

    public function credentialFields(): array
    {
        return [
            new ConfigurationField(
                name: 'token',
                label: 'Token',
                type: ConfigurationFieldType::Password,
            ),
        ];
    }

    public function capabilities(): DestinationProviderCapabilities
    {
        return new DestinationProviderCapabilities(supportsConnectionTest: true);
    }

    public function testConnection(DestinationDefinition $destination): ConnectionTestResult
    {
        return ConnectionTestResult::failure('Rejected Bearer abcdefghijklmnop');
    }

    public function publish(DestinationDefinition $destination, PreparedMessage $message): DeliveryResult
    {
        return DeliveryResult::success('noop');
    }
}
