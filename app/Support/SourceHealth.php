<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Remembers when a scraping source starts returning odd results during its
 * normal fetches (no extra requests), so platform admins see it on /admin.
 * A later normal read clears the flag.
 */
class SourceHealth
{
    private const KEY = 'source-health:%s';

    private const TTL_DAYS = 14;

    /**
     * @param  array<string, mixed>  $context
     */
    public function flag(string $provider, string $message, array $context = []): void
    {
        $key = $this->key($provider);
        /** @var array{first_seen_at?: string, count?: int}|null $existing */
        $existing = Cache::get($key);
        $now = CarbonImmutable::now()->toIso8601String();

        Cache::put($key, [
            'provider' => $provider,
            'message' => $message,
            'context' => $context,
            'first_seen_at' => $existing['first_seen_at'] ?? $now,
            'last_seen_at' => $now,
            'count' => ($existing['count'] ?? 0) + 1,
        ], now()->addDays(self::TTL_DAYS));

        Log::warning("Source health: {$provider}: {$message}", $context);
    }

    public function clear(string $provider): void
    {
        $key = $this->key($provider);

        if (Cache::has($key)) {
            Cache::forget($key);
        }
    }

    /**
     * @return array{provider: string, message: string, context: array<string, mixed>, first_seen_at: string, last_seen_at: string, count: int}|null
     */
    public function current(string $provider): ?array
    {
        $value = Cache::get($this->key($provider));

        return is_array($value) ? $value : null;
    }

    /**
     * @param  list<string>  $providers
     * @return list<array{provider: string, message: string, context: array<string, mixed>, first_seen_at: string, last_seen_at: string, count: int}>
     */
    public function all(array $providers): array
    {
        return array_values(array_filter(array_map(
            fn (string $provider): ?array => $this->current($provider),
            $providers,
        )));
    }

    private function key(string $provider): string
    {
        return sprintf(self::KEY, $provider);
    }
}
