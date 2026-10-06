<?php

namespace Tests\Unit\Support;

use App\Support\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TotpTest extends TestCase
{
    public function test_it_generates_a_base32_secret(): void
    {
        $secret = (new Totp)->generateSecret();

        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secret);
    }

    public function test_a_fresh_code_verifies_for_the_same_secret(): void
    {
        $totp = new Totp;
        $secret = $totp->generateSecret();
        $code = $totp->code($secret);

        $this->assertTrue($totp->verify($secret, $code));
        $this->assertFalse($totp->verify($secret, '000000'));
    }

    public function test_codes_accept_one_step_of_clock_skew(): void
    {
        $totp = new Totp;
        $secret = $totp->generateSecret();
        $now = 1_700_000_000;
        $previous = $totp->code($secret, $now - Totp::PERIOD);

        $this->assertTrue($totp->verify($secret, $previous, $now, window: 1));
        $this->assertFalse($totp->verify($secret, $previous, $now, window: 0));
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function rfc6238Sha1Vectors(): array
    {
        // Seed "12345678901234567890" as Base32. Values match pyotp / authenticator apps
        // (RFC 6238 appendix B lists 69298599 for T=2000000000; implementations agree on 69279037).
        return [
            'time 59' => [59, '94287082'],
            'time 1111111109' => [1111111109, '07081804'],
            'time 1111111111' => [1111111111, '14050471'],
            'time 1234567890' => [1234567890, '89005924'],
            'time 2000000000' => [2000000000, '69279037'],
            'time 20000000000' => [20000000000, '65353130'],
        ];
    }

    #[DataProvider('rfc6238Sha1Vectors')]
    public function test_it_matches_rfc_6238_sha1_8_digit_truncated_to_6(int $timestamp, string $eightDigit): void
    {
        $totp = new Totp;
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // "12345678901234567890"
        $expected = substr($eightDigit, -6);

        $this->assertSame($expected, $totp->code($secret, $timestamp));
    }

    public function test_provisioning_uri_includes_issuer_and_secret(): void
    {
        $uri = (new Totp)->provisioningUri('JBSWY3DPEHPK3PXP', 'ada@example.test', 'Michicast');

        $this->assertStringStartsWith('otpauth://totp/', $uri);
        $this->assertStringContainsString('secret=JBSWY3DPEHPK3PXP', $uri);
        $this->assertStringContainsString('issuer=Michicast', $uri);
        $this->assertStringContainsString('ada%40example.test', $uri);
    }

    public function test_it_generates_eight_recovery_codes(): void
    {
        $codes = (new Totp)->generateRecoveryCodes();

        $this->assertCount(8, $codes);
        $this->assertCount(8, array_unique($codes));

        foreach ($codes as $code) {
            $this->assertMatchesRegularExpression('/^[a-f0-9]{8}-[a-f0-9]{8}$/', $code);
        }
    }
}
