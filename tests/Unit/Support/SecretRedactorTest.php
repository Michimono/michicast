<?php

namespace Tests\Unit\Support;

use App\Support\SecretRedactor;
use PHPUnit\Framework\TestCase;

class SecretRedactorTest extends TestCase
{
    public function test_a_telegram_token_inside_a_bot_api_url_is_redacted(): void
    {
        $redacted = (new SecretRedactor)->redact(
            'cURL error 6 for https://api.telegram.org/bot123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsaw/sendMessage.',
        );

        $this->assertSame('cURL error 6 for https://api.telegram.org/bot[redacted]/sendMessage.', $redacted);
    }

    public function test_remembered_credentials_are_redacted_in_raw_url_encoded_and_json_forms(): void
    {
        $secrets = new SecretRedactor;
        $secrets->remember(['token' => 'plain/secret+value', 'nested' => ['key' => 'another-secret']]);

        $redacted = $secrets->redact(
            'raw plain/secret+value, query '.rawurlencode('plain/secret+value')
            .', json '.json_encode(['t' => 'plain/secret+value'])
            .', nested another-secret',
        );

        $this->assertStringNotContainsString('secret+value', (string) $redacted);
        $this->assertStringNotContainsString(rawurlencode('plain/secret+value'), (string) $redacted);
        $this->assertStringNotContainsString('plain\/secret', (string) $redacted);
        $this->assertStringNotContainsString('another-secret', (string) $redacted);
        $this->assertSame(4, substr_count((string) $redacted, '[redacted]'));
    }

    public function test_explicit_secrets_are_redacted_and_short_values_are_left_alone(): void
    {
        $secrets = new SecretRedactor;
        $secrets->remember(['pin' => 'abc']);

        $this->assertSame(
            'abc and [redacted]',
            $secrets->redact('abc and one-off-secret', ['one-off-secret']),
        );
    }

    public function test_a_stored_secret_is_masked_to_a_short_suffix(): void
    {
        $secrets = new SecretRedactor;

        $this->assertSame('••••wxyz', $secrets->mask('123456789:token-ending-wxyz'));
        $this->assertSame('••••', $secrets->mask('short'));
        $this->assertNull($secrets->mask(''));
        $this->assertNull($secrets->mask(['not', 'a', 'string']));
    }
}
