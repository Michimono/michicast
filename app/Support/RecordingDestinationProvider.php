<?php

namespace App\Support;

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
            'channel' => ['required', 'string'],
            'outcome' => ['sometimes', Rule::in(['success', 'permanent_failure', 'transient_failure'])],
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

        return ConnectionTestResult::success('Authenticated against the recording destination.');
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
