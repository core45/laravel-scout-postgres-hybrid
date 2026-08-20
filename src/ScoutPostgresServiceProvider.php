<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres;

use Core45\ScoutPostgres\Console\ReindexCommand;
use Core45\ScoutPostgres\Contracts\DocumentTypeRegistry;
use Core45\ScoutPostgres\Contracts\EmbeddingProvider;
use Core45\ScoutPostgres\Contracts\ScopeResolver;
use Core45\ScoutPostgres\Embedding\NullEmbeddingProvider;
use Core45\ScoutPostgres\Scope\ScopeDefinition;
use Core45\ScoutPostgres\Search\PostgresDocumentEngine;
use Core45\ScoutPostgres\Support\ConfiguredDocumentTypes;
use Illuminate\Support\ServiceProvider;
use Laravel\Scout\EngineManager;

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

        // The adopter's type set. Bound as a singleton because resolution walks the
        // configured classes and instantiates each one; per-call resolution would
        // repeat that for every row of a result set.
        $this->app->singleton(DocumentTypeRegistry::class, function ($app): DocumentTypeRegistry {
            $types = $app['config']->get('scout-postgres.types', []);

            /** @var list<class-string> $types */
            $types = is_array($types) ? array_values($types) : [];

            return new ConfiguredDocumentTypes($types);
        });

        // Deliberately a default rather than a requirement. With no embedding API
        // configured the semantic branch reports itself unready and searches fall
        // back to keyword plus trigram, so the package is installable and useful
        // before an adopter has any embedding infrastructure at all.
        $this->app->bindIf(EmbeddingProvider::class, NullEmbeddingProvider::class);

        // No default binding for ScopeResolver or ScopeRepository on purpose. Both
        // describe the adopter's tenancy, which the package cannot guess, and a
        // stub that resolved *some* scope would be the exact failure SC-1 exists to
        // prevent. Their absence throws; a wrong guess would not.
    }

    public function boot(): void
    {
        $this->registerEngine();

        if ($this->app->runningInConsole()) {
            $this->commands([ReindexCommand::class]);
        }

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

    /**
     * Register the `postgres` Scout driver.
     *
     * Resolved lazily inside the closure rather than in `register()`: Scout's
     * manager only calls this when `scout.driver` is actually `postgres`, so an
     * application that ships this package while using another driver never
     * constructs the engine and never needs a ScopeResolver bound.
     *
     * The resolver is optional here and required later. Passing it as null lets a
     * single-tenant install work with nothing bound, while a scoped install that
     * forgot to bind one fails at the first search with a message naming the
     * problem — rather than at boot, where the message would be further from the
     * cause.
     */
    private function registerEngine(): void
    {
        resolve(EngineManager::class)->extend('postgres', function ($app): PostgresDocumentEngine {
            return new PostgresDocumentEngine(
                $app->make(ScopeDefinition::class),
                $app->make(DocumentTypeRegistry::class),
                $app->bound(ScopeResolver::class) ? $app->make(ScopeResolver::class) : null,
            );
        });
    }
}
