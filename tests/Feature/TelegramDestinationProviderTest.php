<?php

namespace Tests\Feature;

use App\Enums\DeliveryFailure;
use App\Support\DestinationDefinition;
use App\Support\DestinationProviderRegistry;
use App\Support\PreparedMessage;
use App\Support\TelegramDestinationProvider;
use App\Support\TelegramMessageFormatter;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class TelegramDestinationProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    public function test_the_telegram_provider_is_registered(): void
    {
        $registry = $this->app->make(DestinationProviderRegistry::class);

        $this->assertTrue($registry->has('telegram'));
        $this->assertInstanceOf(TelegramDestinationProvider::class, $registry->get('telegram'));
        $this->assertSame('Telegram', $registry->options()['telegram']);
        $this->assertTrue($registry->capabilities('telegram')->supportsConnectionTest);
    }

    public function test_a_message_publishes_and_returns_the_telegram_message_id(): void
    {
        Http::fake([
            'api.telegram.org/bot*/sendMessage' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 42, 'text' => 'Hello'],
            ], 200),
        ]);

        $result = $this->provider()->publish(
            $this->definition(),
            new PreparedMessage('Hello world', 'https://example.com/posts/1'),
        );

        $this->assertTrue($result->successful);
        $this->assertSame('42', $result->externalReference);

        Http::assertSent(function ($request): bool {
            $data = $request->data();

            return str_contains($request->url(), '/botsecret-bot-token/sendMessage')
                && $data['chat_id'] === '@pocketnews'
                && $data['parse_mode'] === 'HTML'
                && str_contains($data['text'], 'Hello world')
                && str_contains($data['text'], 'https://example.com/posts/1');
        });
    }

    public function test_html_special_characters_are_escaped(): void
    {
        $formatted = (new TelegramMessageFormatter)->format(
            new PreparedMessage('A <B> & C'),
            TelegramMessageFormatter::PARSE_MODE_HTML,
        );

        $this->assertSame('A &lt;B&gt; &amp; C', $formatted['text']);
        $this->assertSame('HTML', $formatted['parse_mode']);
    }

    public function test_markdown_v2_reserved_characters_are_escaped(): void
    {
        $formatted = (new TelegramMessageFormatter)->format(
            new PreparedMessage('Hello_world and 2 * 3'),
            TelegramMessageFormatter::PARSE_MODE_MARKDOWN_V2,
        );

        $this->assertSame('Hello\\_world and 2 \\* 3', $formatted['text']);
        $this->assertSame('MarkdownV2', $formatted['parse_mode']);
    }

    public function test_markdown_v2_preserves_bold_and_normalizes_commonmark(): void
    {
        $formatter = new TelegramMessageFormatter;

        $single = $formatter->format(
            new PreparedMessage('*Hello_world*'),
            TelegramMessageFormatter::PARSE_MODE_MARKDOWN_V2,
        );
        $this->assertSame('*Hello\\_world*', $single['text']);

        $double = $formatter->format(
            new PreparedMessage('**Hello_world**'),
            TelegramMessageFormatter::PARSE_MODE_MARKDOWN_V2,
        );
        $this->assertSame('*Hello\\_world*', $double['text']);

        $mixed = $formatter->format(
            new PreparedMessage('See **title** and plain_text'),
            TelegramMessageFormatter::PARSE_MODE_MARKDOWN_V2,
        );
        $this->assertSame('See *title* and plain\\_text', $mixed['text']);
    }

    public function test_over_long_content_is_truncated_to_telegram_limit(): void
    {
        $formatter = new TelegramMessageFormatter;
        $body = str_repeat('a', TelegramMessageFormatter::MAX_LENGTH + 50);
        $formatted = $formatter->format(new PreparedMessage($body), TelegramMessageFormatter::PARSE_MODE_HTML);

        $this->assertSame(TelegramMessageFormatter::MAX_LENGTH, mb_strlen($formatted['text']));
        $this->assertTrue(str_ends_with($formatted['text'], '…'));
    }

    public function test_html_truncation_does_not_leave_a_broken_entity(): void
    {
        $formatter = new TelegramMessageFormatter;
        // Fill almost to the limit, then end with an ampersand that would start an entity.
        $body = str_repeat('a', TelegramMessageFormatter::MAX_LENGTH - 2).'&';
        $escaped = $formatter->escapeHtml($body);
        $truncated = $formatter->truncate($escaped, TelegramMessageFormatter::PARSE_MODE_HTML);

        $this->assertLessThanOrEqual(TelegramMessageFormatter::MAX_LENGTH, mb_strlen($truncated));
        $this->assertDoesNotMatchRegularExpression('/&[^;]*$/', $truncated);
    }

    public function test_a_bad_chat_id_is_a_permanent_failure(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => false,
                'error_code' => 400,
                'description' => 'Bad Request: chat not found',
            ], 400),
        ]);

        $result = $this->provider()->publish($this->definition(), new PreparedMessage('Hello'));

        $this->assertFalse($result->successful);
        $this->assertSame(DeliveryFailure::Permanent, $result->failure);
        $this->assertSame('Bad Request: chat not found', $result->failureReason);
        $this->assertFalse($result->isRetryable());
    }

    public function test_a_timeout_is_a_transient_failure(): void
    {
        Http::fake([
            'api.telegram.org/*' => function () {
                throw new ConnectionException('cURL error 28: Operation timed out');
            },
        ]);

        $result = $this->provider()->publish($this->definition(), new PreparedMessage('Hello'));

        $this->assertFalse($result->successful);
        $this->assertSame(DeliveryFailure::Transient, $result->failure);
        $this->assertTrue($result->isRetryable());
        $this->assertStringContainsString('did not answer in time', (string) $result->failureReason);
    }

    public function test_a_rate_limit_is_transient_and_carries_retry_after(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => false,
                'error_code' => 429,
                'description' => 'Too Many Requests: retry after 12',
                'parameters' => ['retry_after' => 12],
            ], 429),
        ]);

        $result = $this->provider()->publish($this->definition(), new PreparedMessage('Hello'));

        $this->assertFalse($result->successful);
        $this->assertSame(DeliveryFailure::Transient, $result->failure);
        $this->assertTrue($result->isRetryable());
        $this->assertSame(12, $result->retryAfterSeconds);
    }

    public function test_the_connection_test_sends_a_marked_test_message(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 7],
            ], 200),
        ]);

        $result = $this->provider()->testConnection($this->definition());

        $this->assertTrue($result->successful);
        $this->assertSame('Sent a test message to Telegram (message 7).', $result->message);

        Http::assertSent(function ($request): bool {
            return str_contains($request->data()['text'] ?? '', '[Michicast test]');
        });
    }

    public function test_the_bot_token_never_appears_in_failure_reasons(): void
    {
        $token = '123456:ABC-DEF_secret-token';

        Http::fake([
            'api.telegram.org/*' => function () use ($token) {
                throw new ConnectionException('Failed to connect to https://api.telegram.org/bot'.$token.'/sendMessage');
            },
        ]);

        $result = $this->provider()->publish(
            $this->definition(credentials: ['bot_token' => $token]),
            new PreparedMessage('Hello'),
        );

        $this->assertFalse($result->successful);
        $this->assertStringNotContainsString($token, (string) $result->failureReason);
        $this->assertStringContainsString('[redacted]', (string) $result->failureReason);
    }

    public function test_a_blocked_bot_is_a_permanent_failure(): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => false,
                'error_code' => 403,
                'description' => 'Forbidden: bot was blocked by the user',
            ], 403),
        ]);

        $result = $this->provider()->publish($this->definition(), new PreparedMessage('Hello'));

        $this->assertSame(DeliveryFailure::Permanent, $result->failure);
        $this->assertFalse($result->isRetryable());
    }

    #[DataProvider('parseModeDefaults')]
    public function test_parse_mode_defaults_to_html_when_unset(?string $configured, string $expected): void
    {
        Http::fake([
            'api.telegram.org/*' => Http::response([
                'ok' => true,
                'result' => ['message_id' => 1],
            ], 200),
        ]);

        $config = ['chat_id' => '@pocketnews'];

        if ($configured !== null) {
            $config['parse_mode'] = $configured;
        }

        $this->provider()->publish($this->definition($config), new PreparedMessage('Hi'));

        Http::assertSent(fn ($request): bool => ($request->data()['parse_mode'] ?? null) === $expected);
    }

    /**
     * @return array<string, array{0: string|null, 1: string}>
     */
    public static function parseModeDefaults(): array
    {
        return [
            'unset' => [null, 'HTML'],
            'html' => ['HTML', 'HTML'],
            'markdown v2' => ['MarkdownV2', 'MarkdownV2'],
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $credentials
     */
    private function definition(
        array $config = ['chat_id' => '@pocketnews', 'parse_mode' => 'HTML'],
        array $credentials = ['bot_token' => 'secret-bot-token'],
    ): DestinationDefinition {
        return new DestinationDefinition('telegram', $config, $credentials, 1, 9);
    }

    private function provider(): TelegramDestinationProvider
    {
        return $this->app->make(TelegramDestinationProvider::class);
    }
}
