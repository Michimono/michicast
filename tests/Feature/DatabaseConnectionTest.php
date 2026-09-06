<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class DatabaseConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_suite_runs_against_mysql(): void
    {
        $this->assertSame('mysql', DB::connection()->getDriverName());
    }

    public function test_migrations_run_against_mysql(): void
    {
        $this->assertTrue(Schema::hasTable('users'));
    }
}
