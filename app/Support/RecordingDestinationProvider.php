<?php

namespace App\Support;

use App\Enums\ConfigurationFieldType;
use Illuminate\Validation\Rule;

/**
 * Shown to users as "Activity log (testing)" (#202): instead of sending anywhere, each
 * message is kept on its delivery row (published_message) and shown in the
 * broadcast's delivery history. The internal type stays `recording` so stored
 * destinations need no data migration, and tests can still steer it through
 * its own configuration (outcome).
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
        return 'Activity log (testing)';
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
                hint: 'A name for this log, e.g. the channel you would publish to.',
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
            return ConnectionTestResult::failure('The activity log has no token to authenticate with.');
        }

        $result = $this->publish($destination, new PreparedMessage(
            '[Michicast test] Connection check — you can delete this message.',
        ));

        if ($result->successful) {
            return ConnectionTestResult::success(
                'Sent a test message to the activity log (ref '.$result->externalReference.').',
            );
        }

        return ConnectionTestResult::failure(
            $result->failureReason ?? 'The activity log connection test failed.',
        );
    }

    public function publish(DestinationDefinition $destination, PreparedMessage $message): DeliveryResult
    {
        return match ($destination->config('outcome', 'success')) {
            'permanent_failure' => DeliveryResult::permanentFailure('The activity log rejected the message.'),
            'transient_failure' => DeliveryResult::transientFailure('The activity log did not answer in time.'),
            default => $this->record($destination, $message),
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

    private function record(DestinationDefinition $destination, PreparedMessage $message): DeliveryResult
    {
        $this->published[] = $message;

        $text = $message->body;

        if (is_string($message->link) && trim($message->link) !== '') {
            $text .= "\n\n".trim($message->link);
        }

        $reference = 'recording-'.count($this->published);

        // The internal object as delivered, for analysis. Credentials never go in.
        $payload = [
            'destination' => ['type' => $destination->type, 'id' => $destination->destinationId, 'channel' => $destination->config('channel')],
            'message' => ['body' => $message->body, 'link' => $message->link, 'media' => $message->media],
        ];

        // Every mapped destination field, when a content mapping produced the message.
        if ($message->fields !== []) {
            $payload['fields'] = $message->fields;
        }

        return DeliveryResult::success($reference, $text, $payload);
    }
}
