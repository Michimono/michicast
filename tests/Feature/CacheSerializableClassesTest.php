<?php

namespace Tests\Feature;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use stdClass;
use Tests\TestCase;

class CacheSerializableClassesTest extends TestCase
{
    public function test_pulse_allow_listed_classes_round_trip_through_serializing_cache(): void
    {
        $this->assertSame([
            stdClass::class,
            Collection::class,
            CarbonImmutable::class,
        ], config('cache.serializable_classes'));

        // Use a serializing store (file), not the phpunit array driver (#158 / Pulse #505).
        $directory = storage_path('framework/cache/data/pulse-serializable-test');
        File::ensureDirectoryExists($directory);

        config([
            'cache.stores.file.path' => $directory,
            'cache.stores.file.lock_path' => $directory,
        ]);

        $payload = [
            'row' => (object) ['hits' => 3, 'key' => 'example'],
            'rows' => collect([(object) ['id' => 1], (object) ['id' => 2]]),
            'updated_at' => CarbonImmutable::parse('2026-01-15T12:00:00Z'),
        ];

        Cache::store('file')->put('pulse-serializable-pin', $payload, 60);

        $restored = Cache::store('file')->get('pulse-serializable-pin');

        $this->assertInstanceOf(stdClass::class, $restored['row']);
        $this->assertSame(3, $restored['row']->hits);
        $this->assertInstanceOf(Collection::class, $restored['rows']);
        $this->assertCount(2, $restored['rows']);
        $this->assertInstanceOf(CarbonImmutable::class, $restored['updated_at']);
        $this->assertTrue($restored['updated_at']->equalTo(CarbonImmutable::parse('2026-01-15T12:00:00Z')));

        File::deleteDirectory($directory);
    }
}
