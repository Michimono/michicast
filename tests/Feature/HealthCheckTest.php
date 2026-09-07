<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_the_health_endpoint_returns_200_when_the_database_is_reachable(): void
    {
        $this->get(route('health'))
            ->assertOk()
            ->assertExactJson([
                'application' => 'ok',
                'database' => 'ok',
            ]);
    }

    public function test_the_health_endpoint_returns_503_when_the_database_connection_throws(): void
    {
        DB::shouldReceive('select')
            ->once()
            ->with('select 1')
            ->andThrow(new RuntimeException('SQLSTATE[HY000] [2002] Connection refused'));

        $this->get(route('health'))
            ->assertServiceUnavailable()
            ->assertExactJson([
                'application' => 'ok',
                'database' => 'unavailable',
            ]);
    }
}
