<?php

namespace Tests\Feature;

use App\Enums\ConfigurationFieldType;
use App\Enums\DeliveryFailure;
use App\Exceptions\UnknownDestinationProviderException;
use App\Support\DestinationDefinition;
use App\Support\DestinationProviderRegistry;
use App\Support\PreparedMessage;
use App\Support\RecordingDestinationProvider;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class DestinationProviderRegistryTest extends TestCase
{
    public function test_a_configured_provider_is_resolved_by_its_type_string(): void
    {
        $registry = $this->registry();

        $this->assertTrue($registry->has('recording'));
        $this->assertInstanceOf(RecordingDestinationProvider::class, $registry->get('recording'));
        $this->assertSame('recording', $registry->get('recording')->type());
    }

    public function test_a_provider_can_be_registered_at_runtime(): void
    {
        $registry = $this->registry();
        $registry->register('secondary', RecordingDestinationProvider::class);

        $this->assertContains('secondary', $registry->types());
        $this->assertInstanceOf(RecordingDestinationProvider::class, $registry->get('secondary'));
    }

    public function test_an_unknown_type_fails_with_a_clear_catchable_error(): void
    {
        $this->expectException(UnknownDestinationProviderException::class);
        $this->expectExceptionMessage('No destination provider is registered for the type [nope].');

        $this->registry()->get('nope');
    }

    public function test_the_registry_offers_the_available_types_for_the_destination_form(): void
    {
        $options = $this->registry()->options();

        $this->assertArrayHasKey('recording', $options);
        $this->assertSame('Recording destination', $options['recording']);
    }

    public function test_capabilities_are_queried_without_knowing_the_provider(): void
    {
        $capabilities = $this->registry()->capabilities('recording');

        $this->assertTrue($capabilities->supportsConnectionTest);
        $this->assertFalse($capabilities->supportsMedia);
    }

    public function test_configuration_and_credential_rules_are_declared_separately(): void
    {
        $registry = $this->registry();

        $configurationRules = $registry->configurationRules('recording');
        $credentialRules = $registry->credentialRules('recording');

        $this->assertArrayHasKey('channel', $configurationRules);
        $this->assertArrayNotHasKey('token', $configurationRules);
        $this->assertArrayHasKey('token', $credentialRules);
        $this->assertArrayNotHasKey('channel', $credentialRules);

        $this->assertTrue(Validator::make(['channel' => 'updates'], $configurationRules)->passes());
        $this->assertTrue(Validator::make([], $credentialRules)->fails());
    }

    public function test_a_provider_declares_the_fields_a_form_renders_for_it(): void
    {
        $registry = $this->registry();
        $configFields = $registry->configurationFields('recording');
        $credentialFields = $registry->credentialFields('recording');

        $this->assertCount(1, $configFields);
        $this->assertSame('channel', $configFields[0]->name);
        $this->assertSame(ConfigurationFieldType::Text, $configFields[0]->type);

        $this->assertCount(1, $credentialFields);
        $this->assertSame('token', $credentialFields[0]->name);
        $this->assertSame(ConfigurationFieldType::Password, $credentialFields[0]->type);
    }

    public function test_a_refused_connection_comes_back_as_a_result(): void
    {
        $provider = $this->provider();

        $authenticated = $provider->testConnection($this->definition());
        $refused = $provider->testConnection($this->definition(credentials: []));

        $this->assertTrue($authenticated->successful);
        $this->assertFalse($refused->successful);
        $this->assertSame('The recording destination has no token to authenticate with.', $refused->message);
    }

    public function test_publishing_reports_success_with_the_destination_reference(): void
    {
        $provider = $this->provider();
        $message = new PreparedMessage('Hello', 'https://example.com/posts/1');

        $result = $provider->publish($this->definition(), $message);

        $this->assertTrue($result->successful);
        $this->assertSame('recording-1', $result->externalReference);
        $this->assertNull($result->failure);
        $this->assertFalse($result->isRetryable());
        $this->assertSame([$message], $provider->published());
    }

    public function test_a_rejected_message_is_reported_as_a_permanent_failure(): void
    {
        $provider = $this->provider();

        $result = $provider->publish(
            $this->definition(['channel' => 'updates', 'outcome' => 'permanent_failure']),
            new PreparedMessage('Hello'),
        );

        $this->assertFalse($result->successful);
        $this->assertSame(DeliveryFailure::Permanent, $result->failure);
        $this->assertSame('The recording destination rejected the message.', $result->failureReason);
        $this->assertFalse($result->isRetryable());
        $this->assertSame([], $provider->published());
    }

    public function test_a_timeout_is_reported_as_a_transient_failure(): void
    {
        $provider = $this->provider();

        $result = $provider->publish(
            $this->definition(['channel' => 'updates', 'outcome' => 'transient_failure']),
            new PreparedMessage('Hello'),
        );

        $this->assertFalse($result->successful);
        $this->assertSame(DeliveryFailure::Transient, $result->failure);
        $this->assertTrue($result->isRetryable());
    }

    public function test_credentials_stay_out_of_debug_output(): void
    {
        ob_start();
        var_dump($this->definition());
        $dump = (string) ob_get_clean();

        $this->assertStringNotContainsString('super-secret-token', $dump);
        $this->assertStringContainsString('[redacted]', $dump);
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $credentials
     */
    private function definition(
        array $config = ['channel' => 'updates'],
        array $credentials = ['token' => 'super-secret-token'],
    ): DestinationDefinition {
        return new DestinationDefinition('recording', $config, $credentials, 1, 7);
    }

    private function registry(): DestinationProviderRegistry
    {
        return $this->app->make(DestinationProviderRegistry::class);
    }

    /**
     * Publishing is asserted against the recorder itself, because reading back
     * what it kept is not part of the contract.
     */
    private function provider(): RecordingDestinationProvider
    {
        return new RecordingDestinationProvider;
    }
}
