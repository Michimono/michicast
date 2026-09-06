<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('db:prepare')]
#[Description('Create the application and test schemas on the default connection when they are missing')]
class PrepareDatabaseCommand extends Command
{
    /**
     * Name of the throwaway connection used to reach the server without selecting a schema.
     */
    private const SERVER_CONNECTION = 'db-prepare-server';

    public function handle(): int
    {
        $connection = Config::string('database.default');

        /** @var array{driver?: string, database?: string, charset?: string, collation?: string} $config */
        $config = Config::array("database.connections.{$connection}");

        if (($config['driver'] ?? null) !== 'mysql') {
            $this->components->warn("The [{$connection}] connection is not MySQL, so there is nothing to prepare.");

            return self::SUCCESS;
        }

        $database = $config['database'] ?? '';

        if (! $this->isValidSchemaName($database)) {
            $this->components->error("Refusing to create the schema [{$database}]: only letters, numbers and underscores are allowed.");

            return self::FAILURE;
        }

        Config::set('database.connections.'.self::SERVER_CONNECTION, [...$config, 'database' => null]);

        try {
            foreach ([$database, $database.'_test'] as $schema) {
                $this->createSchema($schema, $config);
                $this->components->info("Schema [{$schema}] is ready.");
            }
        } catch (Throwable $exception) {
            $this->components->error('Could not reach the database server: '.$exception->getMessage());
            $this->components->warn('Check DB_HOST, DB_USERNAME and DB_PASSWORD in your .env file, then run `php artisan db:prepare` again.');

            return self::FAILURE;
        } finally {
            DB::purge(self::SERVER_CONNECTION);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array{charset?: string, collation?: string}  $config
     */
    private function createSchema(string $schema, array $config): void
    {
        $charset = $config['charset'] ?? 'utf8mb4';
        $collation = $config['collation'] ?? 'utf8mb4_unicode_ci';

        DB::connection(self::SERVER_CONNECTION)->statement(
            "CREATE DATABASE IF NOT EXISTS `{$schema}` CHARACTER SET `{$charset}` COLLATE `{$collation}`"
        );
    }

    private function isValidSchemaName(string $schema): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $schema) === 1;
    }
}
