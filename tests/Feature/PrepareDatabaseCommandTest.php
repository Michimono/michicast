<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PrepareDatabaseCommandTest extends TestCase
{
    /**
     * Schema the command is pointed at so the suite never touches michicast_test itself.
     */
    private const SCRATCH_SCHEMA = 'michicast_db_prepare_scratch';

    private const SERVER_CONNECTION = 'db-prepare-test-server';

    protected function tearDown(): void
    {
        foreach ([self::SCRATCH_SCHEMA, self::SCRATCH_SCHEMA.'_test'] as $schema) {
            $this->serverConnection()->statement("DROP DATABASE IF EXISTS `{$schema}`");
        }

        DB::purge(self::SERVER_CONNECTION);

        parent::tearDown();
    }

    public function test_it_creates_the_application_and_test_schemas(): void
    {
        Config::set('database.connections.mysql.database', self::SCRATCH_SCHEMA);

        $this->artisan('db:prepare')->assertSuccessful();

        $this->assertTrue($this->schemaExists(self::SCRATCH_SCHEMA));
        $this->assertTrue($this->schemaExists(self::SCRATCH_SCHEMA.'_test'));
    }

    public function test_it_can_run_again_once_the_schemas_exist(): void
    {
        Config::set('database.connections.mysql.database', self::SCRATCH_SCHEMA);

        $this->artisan('db:prepare')->assertSuccessful();
        $this->artisan('db:prepare')->assertSuccessful();

        $this->assertTrue($this->schemaExists(self::SCRATCH_SCHEMA));
    }

    public function test_it_refuses_a_schema_name_that_is_not_a_bare_identifier(): void
    {
        Config::set('database.connections.mysql.database', 'michicast`; drop database michicast');

        $this->artisan('db:prepare')->assertFailed();
    }

    public function test_it_leaves_connections_that_are_not_mysql_alone(): void
    {
        Config::set('database.default', 'sqlite');

        $this->artisan('db:prepare')->assertSuccessful();

        $this->assertFalse($this->schemaExists(self::SCRATCH_SCHEMA));
    }

    private function schemaExists(string $schema): bool
    {
        return $this->serverConnection()->selectOne(
            'select schema_name from information_schema.schemata where schema_name = ?',
            [$schema]
        ) !== null;
    }

    private function serverConnection(): Connection
    {
        Config::set('database.connections.'.self::SERVER_CONNECTION, [
            ...Config::array('database.connections.mysql'),
            'database' => null,
        ]);

        return DB::connection(self::SERVER_CONNECTION);
    }
}
