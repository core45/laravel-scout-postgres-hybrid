<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres;

use Illuminate\Support\ServiceProvider;

/**
 * Registers the `postgres` Scout engine.
 *
 * P0 scaffold: this provider currently publishes config and migrations only.
 * The `EngineManager::extend('postgres', …)` registration lands in P5, once the
 * contracts from P1 and the ported engine from P2 exist to register.
 */
class ScoutPostgresServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/scout-postgres.php', 'scout-postgres');
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/scout-postgres.php' => config_path('scout-postgres.php'),
        ], 'scout-postgres-config');

        // Published separately from the schema migration on purpose. `CREATE EXTENSION`
        // needs elevated privileges, which an ordinary app role on managed PostgreSQL
        // (RDS, Cloud SQL, Supabase) does not have. An adopter whose DBA pre-creates
        // `vector`, `pg_trgm` and `unaccent` publishes the schema migration alone and
        // skips this one entirely.
        $this->publishes([
            __DIR__.'/../database/migrations/extensions' => database_path('migrations'),
        ], 'scout-postgres-extensions-migration');

        $this->publishes([
            __DIR__.'/../database/migrations/schema' => database_path('migrations'),
        ], 'scout-postgres-migrations');
    }
}
