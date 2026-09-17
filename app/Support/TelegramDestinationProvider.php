<?php

namespace App\Support;

use App\Enums\ConfigurationFieldType;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Publishes a prepared message to a Telegram chat through the Bot API.
 */
class TelegramDestinationProvider implements DestinationProvider
{
    private const TIMEOUT_SECONDS = 10;

    private const CONNECT_TIMEOUT_SECONDS = 5;

    private const RETRY_TIMES = 2;

    private const RETRY_SLEEP_MS = 200;

    private const API_BASE = 'https://api.telegram.org';

    public function __construct(private TelegramMessageFormatter $formatter = new TelegramMessageFormatter) {}

    public function type(): string
    {
        return 'telegram';
    }

    public function label(): string
    {
        return 'Telegram';
    }

    /**
     * @return array<string, mixed>
     */
    public function configurationRules(): array
    {
        return [
            'chat_id' => ['required', 'string', 'max:255'],
            'parse_mode' => ['nullable', 'string', Rule::in([
                TelegramMessageFormatter::PARSE_MODE_HTML,
                TelegramMessageFormatter::PARSE_MODE_MARKDOWN_V2,
            ])],
        ];
    }

    /**
     * @return list<ConfigurationField>
     */
    public function configurationFields(): array
    {
        return [
            new ConfigurationField(
                name: 'chat_id',
                label: 'Chat ID',
                type: ConfigurationFieldType::Text,
                hint: 'A numeric chat id or a channel username such as @mychannel.',
            ),
            new ConfigurationField(
                name: 'parse_mode',
                label: 'Parse mode',
                type: ConfigurationFieldType::Select,
                hint: 'How Telegram should interpret formatting in the message.',
                options: [
                    TelegramMessageFormatter::PARSE_MODE_HTML => 'HTML',
                    TelegramMessageFormatter::PARSE_MODE_MARKDOWN_V2 => 'Markdown V2',
                ],
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function credentialRules(): array
    {
        return [
            'bot_token' => ['required', 'string'],
        ];
    }

    /**
     * @return list<ConfigurationField>
     */
    public function credentialFields(): array
    {
        return [
            new ConfigurationField(
                name: 'bot_token',
                label: 'Bot token',
                type: ConfigurationFieldType::Password,
                hint: 'From BotFather. Leave blank on edit to keep the stored value.',
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
        $token = $this->token($destination);

        if ($token === null) {
            return ConnectionTestResult::failure('The Telegram destination has no bot token.');
        }

        $chatId = $this->chatId($destination);

        if ($chatId === null) {
            return ConnectionTestResult::failure('The Telegram destination has no chat id.');
        }

        $result = $this->publish($destination, new PreparedMessage(
            '[Michicast test] Connection check — you can delete this message.',
        ));

        if ($result->successful) {
            return ConnectionTestResult::success(
                'Sent a test message to Telegram (message '.$result->externalReference.').',
            );
        }

        return ConnectionTestResult::failure($result->failureReason ?? 'The Telegram connection test failed.');
    }

    public function publish(DestinationDefinition $destination, PreparedMessage $message): DeliveryResult
    {
        $token = $this->token($destination);

        if ($token === null) {
            return DeliveryResult::permanentFailure('The Telegram destination has no bot token.');
        }

        $chatId = $this->chatId($destination);

        if ($chatId === null) {
            return DeliveryResult::permanentFailure('The Telegram destination has no chat id.');
        }

        $formatted = $this->formatter->format(
            $message,
            is_string($destination->config('parse_mode'))
                ? $destination->config('parse_mode')
                : TelegramMessageFormatter::PARSE_MODE_HTML,
        );

        try {
            $response = $this->send($token, 'sendMessage', [
                'chat_id' => $chatId,
                'text' => $formatted['text'],
                'parse_mode' => $formatted['parse_mode'],
                'disable_web_page_preview' => false,
            ]);
        } catch (ConnectionException $exception) {
            return DeliveryResult::transientFailure(
                $this->redact('Telegram did not answer in time.', $token, $exception),
            );
        } catch (Throwable $exception) {
            return DeliveryResult::transientFailure(
                $this->redact('Telegram could not be reached.', $token, $exception),
            );
        }

        return $this->deliveryFromResponse($response, $token);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function send(string $token, string $method, array $payload): Response
    {
        return Http::baseUrl(self::API_BASE)
            ->timeout(self::TIMEOUT_SECONDS)
            ->connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
            ->retry(self::RETRY_TIMES, self::RETRY_SLEEP_MS, throw: false)
            ->asJson()
            ->post($this->methodPath($token, $method), $payload);
    }

    private function methodPath(string $token, string $method): string
    {
        return '/bot'.$token.'/'.$method;
    }

    private function deliveryFromResponse(Response $response, string $token): DeliveryResult
    {
        $status = $response->status();
        $body = $response->json();
        $description = is_array($body) && is_string($body['description'] ?? null)
            ? $this->redact($body['description'], $token)
            : null;

        if ($response->successful() && is_array($body) && ($body['ok'] ?? false) === true) {
            $messageId = data_get($body, 'result.message_id');

            return DeliveryResult::success(
                is_numeric($messageId) ? (string) $messageId : null,
            );
        }

        if ($status === 429) {
            $retryAfter = data_get($body, 'parameters.retry_after');

            return DeliveryResult::transientFailure(
                $description ?? 'Telegram rate-limited the request.',
                is_numeric($retryAfter) ? (int) $retryAfter : null,
            );
        }

        if ($status >= 500) {
            return DeliveryResult::transientFailure(
                $description ?? 'Telegram returned HTTP '.$status.'.',
            );
        }

        return DeliveryResult::permanentFailure(
            $description ?? 'Telegram rejected the message (HTTP '.$status.').',
        );
    }

    private function token(DestinationDefinition $destination): ?string
    {
        $token = $destination->credential('bot_token');

        if (! is_string($token)) {
            return null;
        }

        $token = trim($token);

        return $token === '' ? null : $token;
    }

    private function chatId(DestinationDefinition $destination): ?string
    {
        $chatId = $destination->config('chat_id');

        if (! is_string($chatId) && ! is_numeric($chatId)) {
            return null;
        }

        $chatId = trim((string) $chatId);

        return $chatId === '' ? null : $chatId;
    }

    private function redact(string $message, string $token, ?Throwable $exception = null): string
    {
        $detail = $exception?->getMessage();

        if (is_string($detail) && $detail !== '') {
            $message .= ' '.$detail;
        }

        return str_replace($token, '[redacted]', $message);
    }
}
