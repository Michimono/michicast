<?php

namespace App\Support;

use App\Enums\ConfigurationFieldType;
use Illuminate\Validation\Rule;

/**
 * A destination that records what it would have published instead of sending
 * it. It keeps the registry and the destination screens buildable until the
 * first real provider lands, and gives tests a destination they can steer
 * through its own configuration.
 */
class RecordingDestinationProvider implements DestinationProvider
{
    /**
     * @var list<PreparedMessage>
     */
    private array $published = [];

    public function type(): string
    {
        return 'recording';
    }

    public function label(): string
    {
        return 'Recording destination';
    }

    /**
     * @return array<string, mixed>
     */
    public function configurationRules(): array
    {
        return [
            'channel' => ['required', 'string', 'max:255'],
            'outcome' => ['sometimes', Rule::in(['success', 'permanent_failure', 'transient_failure'])],
        ];
    }

    /**
     * @return list<ConfigurationField>
     */
    public function configurationFields(): array
    {
        return [
            new ConfigurationField(
                name: 'channel',
                label: 'Channel',
                type: ConfigurationFieldType::Text,
                hint: 'Where this recording destination pretends to publish.',
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function credentialRules(): array
    {
        return [
            'token' => ['required', 'string'],
        ];
    }

    /**
     * @return list<ConfigurationField>
     */
    public function credentialFields(): array
    {
        return [
            new ConfigurationField(
                name: 'token',
                label: 'Token',
                type: ConfigurationFieldType::Password,
                hint: 'Shown only when you type it. Leave blank on edit to keep the stored value.',
            ),
        ];
    }

    public function capabilities(): DestinationProviderCapabilities
    {
        return new DestinationProviderCapabilities(
            supportsConnectionTest: true,
            supportsMedia: false,
        );
    }

    public function testConnection(DestinationDefinition $destination): ConnectionTestResult
    {
        $token = $destination->credential('token');

        if (! is_string($token) || trim($token) === '') {
            return ConnectionTestResult::failure('The recording destination has no token to authenticate with.');
        }

        $result = $this->publish($destination, new PreparedMessage(
            '[Michicast test] Connection check — you can delete this message.',
        ));

        if ($result->successful) {
            return ConnectionTestResult::success(
                'Sent a test message to the recording destination (ref '.$result->externalReference.').',
            );
        }

        return ConnectionTestResult::failure(
            $result->failureReason ?? 'The recording destination connection test failed.',
        );
    }

    public function publish(DestinationDefinition $destination, PreparedMessage $message): DeliveryResult
    {
        return match ($destination->config('outcome', 'success')) {
            'permanent_failure' => DeliveryResult::permanentFailure('The recording destination rejected the message.'),
            'transient_failure' => DeliveryResult::transientFailure('The recording destination did not answer in time.'),
            default => $this->record($message),
        };
    }

    /**
     * The messages this provider was asked to publish, in order.
     *
     * @return list<PreparedMessage>
     */
    public function published(): array
    {
        return $this->published;
    }

    private function record(PreparedMessage $message): DeliveryResult
    {
        $this->published[] = $message;

        return DeliveryResult::success('recording-'.count($this->published));
    }
}
