<?php

namespace Tests\Feature;

use App\Enums\Role;
use App\Models\Broadcast;
use App\Models\Destination;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectRun;
use App\Models\Source;
use App\Models\User;
use App\Support\ConnectionTestResult;
use App\Support\DeliveryResult;
use App\Support\DestinationDefinition;
use App\Support\DestinationProviderRegistry;
use App\Support\ExecutingProjectRunner;
use App\Support\PreparedMessage;
use App\Support\RecordingDestinationProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class CredentialRedactionTest extends TestCase
{
    use RefreshDatabase;

    private const TELEGRAM_TOKEN = '123456789:AAHcredentialAuditTelegramToken_xyz';

    private const RECORDING_SECRET = 'recording/secret+value-xyz';

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        $this->logPath = storage_path('logs/credential-redaction-test.log');
        $this->removeLog();

        config([
            'logging.default' => 'single',
            'logging.channels.single.path' => $this->logPath,
            'logging.channels.single.level' => 'debug',
        ]);
        Log::forgetChannel('single');
    }

    protected function tearDown(): void
    {
        $this->removeLog();

        parent::tearDown();
    }

    public function test_a_failed_telegram_call_stores_and_shows_no_bot_token(): void
    {
        Http::fake(['api.telegram.org/*' => Http::failedConnection()]);
        config(['michicast.retry.max_attempts' => 1]);

        [$user, $organization, $project, $broadcast] = $this->broadcastTo('telegram', ['chat_id' => '@news'], [
            'bot_token' => self::TELEGRAM_TOKEN,
        ]);

        $this->runner()->run($project);

        $delivery = $broadcast->deliveries()->sole();
        $this->assertStringContainsString('api.telegram.org/bot[redacted]/sendMessage', (string) $delivery->error_detail);
        $this->assertPersistedRecordsExclude(self::TELEGRAM_TOKEN);

        $this->actingAs($user)
            ->get(route('broadcast-deliveries.index', [$organization, $broadcast, 'status' => 'failed']))
            ->assertOk()
            ->assertSee('bot[redacted]', false)
            ->assertDontSee(self::TELEGRAM_TOKEN);

        Log::error('Telegram delivery failed.', ['detail' => $delivery->error_detail, 'url' => 'https://api.telegram.org/bot'.self::TELEGRAM_TOKEN.'/sendMessage']);
        $this->assertLogExcludes(self::TELEGRAM_TOKEN);
    }

    public function test_a_provider_exception_is_redacted_in_the_log_and_every_run_record(): void
    {
        config([
            'michicast.retry.max_attempts' => 1,
            'michicast.failure.broadcast_pause_after' => 1,
        ]);
        $this->app->instance(RecordingDestinationProvider::class, new class extends RecordingDestinationProvider
        {
            public function publish(DestinationDefinition $destination, PreparedMessage $message): DeliveryResult
            {
                $token = (string) $destination->credential('token');

                throw new RuntimeException('Request to https://example.test/send?key='.rawurlencode($token).' failed with key '.$token);
            }
        });
        $this->app->make(DestinationProviderRegistry::class)->register('recording', RecordingDestinationProvider::class);

        [$user, $organization, $project, $broadcast] = $this->broadcastTo('recording', ['channel' => 'updates'], [
            'token' => self::RECORDING_SECRET,
        ]);

        $this->runner()->run($project);

        $log = $this->assertLogExcludes(self::RECORDING_SECRET);
        $this->assertStringContainsString('RuntimeException', $log);
        $this->assertStringContainsString('failed with key [redacted]', $log);
        $this->assertStringNotContainsString(rawurlencode(self::RECORDING_SECRET), $log);

        $this->assertPersistedRecordsExclude(self::RECORDING_SECRET);
        $this->assertSame(
            'Request to https://example.test/send?key=[redacted] failed with key [redacted]',
            $broadcast->deliveries()->sole()->error_detail,
        );
        $this->assertStringContainsString('[redacted]', (string) $broadcast->refresh()->failure_pause_reason);

        $run = ProjectRun::query()->sole();

        foreach ([
            route('broadcasts.show', [$organization, $project]),
            route('broadcast-runs.show', [$organization, $project, $run]),
            route('broadcast-deliveries.index', [$organization, $broadcast]),
        ] as $page) {
            $this->actingAs($user)->get($page)->assertOk()->assertDontSee(self::RECORDING_SECRET);
        }
    }

    public function test_an_exception_during_a_connection_test_renders_and_logs_no_credential(): void
    {
        $this->app->instance(RecordingDestinationProvider::class, new ThrowingConnectionTestDestinationProvider);
        $this->app->make(DestinationProviderRegistry::class)->register('recording', RecordingDestinationProvider::class);

        [$user, $organization, , , $destination] = $this->broadcastTo('recording', ['channel' => 'updates'], [
            'token' => self::RECORDING_SECRET,
        ]);

        $this->actingAs($user)
            ->post(route('destination-connection-tests.store', [$organization, $destination]), [
                'name' => $destination->name,
                'config' => ['channel' => 'updates'],
                'credentials' => ['token' => ''],
            ])
            ->assertOk()
            ->assertSee('The connection test failed unexpectedly.')
            ->assertDontSee(self::RECORDING_SECRET);

        $this->assertStringNotContainsString(self::RECORDING_SECRET, (string) $destination->refresh()->last_test_message);
        $this->assertStringContainsString('Handshake rejected for [redacted]', $this->assertLogExcludes(self::RECORDING_SECRET));
    }

    public function test_validation_errors_never_echo_a_credential(): void
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->create();

        $this->actingAs($user)
            ->postJson(route('broadcast-destinations.store', [$organization, $project]), [
                'name' => 'Telegram channel',
                'type' => 'telegram',
                'config' => ['chat_id' => ''],
                'credentials' => ['bot_token' => self::TELEGRAM_TOKEN],
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('config.chat_id')
            ->assertDontSee(self::TELEGRAM_TOKEN);

        $this->actingAs($user)
            ->from(route('broadcasts.show', [$organization, $project]))
            ->post(route('broadcast-destinations.store', [$organization, $project]), [
                'name' => 'Telegram channel',
                'type' => 'telegram',
                'config' => ['chat_id' => '@news'],
                'credentials' => ['bot_token' => [self::TELEGRAM_TOKEN]],
            ])
            ->assertSessionHasErrors('credentials.bot_token');

        $this->assertStringNotContainsString(self::TELEGRAM_TOKEN, (string) json_encode(session()->all()));
        $this->assertSame(0, $project->destinations()->count());
    }

    public function test_the_edit_form_shows_a_stored_credential_only_as_a_masked_suffix(): void
    {
        [$user, $organization, , , $destination] = $this->broadcastTo('telegram', ['chat_id' => '@news'], [
            'bot_token' => self::TELEGRAM_TOKEN,
        ]);

        $this->actingAs($user)
            ->get(route('destinations.edit', [$organization, $destination]))
            ->assertOk()
            ->assertSee('Stored value ••••_xyz. Leave blank to keep the stored value.')
            ->assertDontSee(self::TELEGRAM_TOKEN)
            ->assertDontSee('123456789');
    }

    public function test_a_destination_definition_serializes_without_credentials(): void
    {
        $definition = Destination::factory()->create([
            'credentials' => ['token' => self::RECORDING_SECRET],
        ])->toDefinition();

        $this->assertStringNotContainsString('secret+value', (string) json_encode($definition));
        $this->assertStringNotContainsString('secret+value', print_r($definition, true));
        $this->assertSame(self::RECORDING_SECRET, $definition->credential('token'));
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $credentials
     * @return array{User, Organization, Project, Broadcast, Destination}
     */
    private function broadcastTo(string $type, array $config, array $credentials): array
    {
        [$user, $organization] = $this->memberHolding(Role::Editor);
        $project = Project::factory()->for($organization)->due()->create();
        $source = Source::factory()->create([
            'project_id' => $project->getKey(),
            'config' => [
                'feed_url' => 'https://example.com/feed.xml',
                'items' => [[
                    'external_id' => 'item-1',
                    'title' => 'Credential audit item',
                    'canonical_url' => 'https://example.com/item-1',
                    'published_at' => now()->addHour()->toIso8601String(),
                ]],
            ],
        ]);
        $destination = Destination::factory()->create([
            'project_id' => $project->getKey(),
            'type' => $type,
            'config' => $config,
            'credentials' => $credentials,
        ]);
        $broadcast = Broadcast::factory()->create([
            'project_id' => $project->getKey(),
            'source_id' => $source->getKey(),
            'destination_id' => $destination->getKey(),
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);

        return [$user, $organization, $project, $broadcast, $destination];
    }

    private function assertPersistedRecordsExclude(string $secret): void
    {
        foreach (['project_runs', 'broadcast_runs', 'broadcast_run_skips', 'content_deliveries', 'content_delivery_attempts', 'broadcasts'] as $table) {
            $rows = (string) json_encode(DB::table($table)->get(), JSON_UNESCAPED_SLASHES);

            $this->assertStringNotContainsString($secret, $rows, $table.' stored a credential.');
            $this->assertStringNotContainsString(rawurlencode($secret), $rows, $table.' stored an encoded credential.');
        }

        $this->assertNotSame(0, DB::table('content_delivery_attempts')->count());
    }

    private function assertLogExcludes(string $secret): string
    {
        $this->assertFileExists($this->logPath);
        $log = (string) file_get_contents($this->logPath);

        $this->assertStringContainsString('[redacted]', $log);
        $this->assertStringNotContainsString($secret, $log);

        return $log;
    }

    private function removeLog(): void
    {
        if (is_file($this->logPath)) {
            unlink($this->logPath);
        }
    }

    private function runner(): ExecutingProjectRunner
    {
        return $this->app->make(ExecutingProjectRunner::class);
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

class ThrowingConnectionTestDestinationProvider extends RecordingDestinationProvider
{
    public function testConnection(DestinationDefinition $destination): ConnectionTestResult
    {
        throw new RuntimeException('Handshake rejected for '.$destination->credential('token'));
    }
}
