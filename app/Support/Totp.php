<?php

namespace App\Support;

/**
 * RFC 6238 TOTP (SHA-1, 30s step, 6 digits) for authenticator apps.
 * No third-party package: keeps enrollment optional without dependency friction.
 */
class Totp
{
    public const DIGITS = 6;

    public const PERIOD = 30;

    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /**
     * Cryptographically random Base32 secret (160 bits / 32 characters).
     */
    public function generateSecret(int $bytes = 20): string
    {
        return $this->base32Encode(random_bytes($bytes));
    }

    /**
     * Current or time-step-specific 6-digit code for a Base32 secret.
     */
    public function code(#[\SensitiveParameter] string $secret, ?int $timestamp = null): string
    {
        $timestamp ??= time();
        $counter = intdiv($timestamp, self::PERIOD);
        $key = $this->base32Decode($secret);
        $binaryCounter = pack('N*', 0, $counter);
        $hash = hash_hmac('sha1', $binaryCounter, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $value = (
            ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF)
        );

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    /**
     * Accept a code for the current step or one step either side (clock skew).
     */
    public function verify(#[\SensitiveParameter] string $secret, string $code, ?int $timestamp = null, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';

        if (! preg_match('/^\d{'.self::DIGITS.'}$/', $code)) {
            return false;
        }

        $timestamp ??= time();

        for ($step = -$window; $step <= $window; $step++) {
            $candidate = $this->code($secret, $timestamp + ($step * self::PERIOD));

            if (hash_equals($candidate, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * otpauth URI for authenticator apps (also encoded into the enrollment QR).
     */
    public function provisioningUri(#[\SensitiveParameter] string $secret, string $email, string $issuer): string
    {
        $label = rawurlencode($issuer.':'.$email);
        $query = http_build_query([
            'secret' => $secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);

        return 'otpauth://totp/'.$label.'?'.$query;
    }

    /**
     * Eight single-use recovery codes shown once at confirmation / regeneration.
     *
     * @return list<string>
     */
    public function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $codes[] = strtolower(bin2hex(random_bytes(4)).'-'.bin2hex(random_bytes(4)));
        }

        return $codes;
    }

    private function base32Encode(string $data): string
    {
        $binary = '';

        foreach (str_split($data) as $char) {
            $binary .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $encoded = '';

        foreach (str_split($binary, 5) as $chunk) {
            if (strlen($chunk) < 5) {
                $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            }

            $encoded .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $encoded;
    }

    private function base32Decode(#[\SensitiveParameter] string $secret): string
    {
        $secret = strtoupper(preg_replace('/\s+/', '', $secret) ?? '');
        $secret = rtrim($secret, '=');
        $binary = '';

        foreach (str_split($secret) as $char) {
            $index = strpos(self::BASE32_ALPHABET, $char);

            if ($index === false) {
                throw new \InvalidArgumentException('Invalid Base32 secret.');
            }

            $binary .= str_pad(decbin($index), 5, '0', STR_PAD_LEFT);
        }

        $data = '';

        foreach (str_split($binary, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $data .= chr(bindec($chunk));
            }
        }

        return $data;
    }
}
