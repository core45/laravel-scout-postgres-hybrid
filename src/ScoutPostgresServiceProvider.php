<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres;

use Core45\ScoutPostgres\Scope\ScopeDefinition;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the `postgres` Scout engine.
 *
 * P1: this provider publishes config and migrations, and resolves the scope
 * configuration into a `ScopeDefinition`. The `EngineManager::extend('postgres', …)`
 * registration lands in P5, once the ported engine from P2 exists to register.
 */
class ScoutPostgresServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/scout-postgres.php', 'scout-postgres');

        // SC-2. Bound as a singleton so the config array is validated once, at first
        // resolution, and every consumer thereafter reads the same object — the
        // migration included. There is no second place to read the mode from, which
        // is what makes a table built in one mode and queried in the other
        // unrepresentable rather than merely discouraged.
        //
        // Not resolved eagerly here: `register()` runs for every artisan command,
        // including `config:publish`, and a package that refuses to boot is a package
        // an adopter cannot fix their config with.
        $this->app->singleton(ScopeDefinition::class, function ($app): ScopeDefinition {
            $scope = $app['config']->get('scout-postgres.scope');

            return ScopeDefinition::fromConfig(is_array($scope) ? $scope : []);
        });
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
