<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Tests;

use Core45\ScoutPostgres\ScoutPostgresServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ScoutPostgresServiceProvider::class];
    }

    /**
     * Unlike most package test suites, this one cannot fall back to SQLite. The
     * engine is PostgreSQL-only by construction — tsvector, pg_trgm and pgvector
     * have no SQLite equivalent — so a green suite on SQLite would prove nothing.
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', [
            'driver' => 'pgsql',
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'testing'),
            'username' => env('DB_USERNAME', 'postgres'),
            'password' => env('DB_PASSWORD', 'postgres'),
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ]);
    }

    /**
     * The extensions migration must run before any schema migration, because the
     * schema declares a `vector` column and GIN indexes that do not exist as types
     * until `CREATE EXTENSION` has run. Testbench loads these in the order given.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations/extensions');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations/schema');
    }
}
