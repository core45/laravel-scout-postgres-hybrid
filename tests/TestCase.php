<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Tests;

use Core45\ScoutPostgres\Contracts\ScopeRepository;
use Core45\ScoutPostgres\Contracts\ScopeResolver;
use Core45\ScoutPostgres\ScoutPostgresServiceProvider;
use Core45\ScoutPostgres\Tests\Fixtures\ArticleType;
use Core45\ScoutPostgres\Tests\Fixtures\FixtureScope;
use Laravel\Scout\ScoutServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * Whether this suite runs against a scope column. Overridden by the
     * single-tenant suite so the whole Feature suite runs twice — once in each
     * mode — which is the only way the scope abstraction is actually tested
     * rather than merely configured.
     */
    protected bool $scoped = true;

    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ScoutServiceProvider::class, ScoutPostgresServiceProvider::class];
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

        $app['config']->set('scout.driver', 'postgres');

        // Scout's queue is off so `searchable()` writes synchronously; the queued
        // path has its own tests rather than being implicit in every other one.
        $app['config']->set('scout.queue', false);

        $app['config']->set('scout-postgres.scope', $this->scoped
            ? [
                'mode' => 'column',
                'column' => 'tenant_id',
                'table' => 'tenants',
                'foreign_key' => true,
                'on_delete' => 'cascade',
                'nullable' => false,
            ]
            : ['mode' => 'none']);

        $app['config']->set('scout-postgres.types', [ArticleType::class]);

        $app->singleton(ScopeResolver::class, fn (): FixtureScope => new FixtureScope);
        $app->singleton(ScopeRepository::class, fn (): FixtureScope => new FixtureScope);
    }

    /**
     * The extensions migration must run before any schema migration, because the
     * schema declares a `vector` column and GIN indexes that do not exist as types
     * until `CREATE EXTENSION` has run. Testbench loads these in the order given.
     *
     * The fixture tables come before the package schema too: the scope foreign key
     * references `tenants`, which has to exist first.
     */
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations/extensions');
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations/schema');
    }

    protected function setUp(): void
    {
        parent::setUp();

        FixtureScope::bind(null);
    }
}
