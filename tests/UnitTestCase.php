<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Tests;

use Core45\ScoutPostgres\ScoutPostgresServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * A booted application with no database.
 *
 * The Feature suite is PostgreSQL-only by construction and deliberately has no
 * SQLite fallback, but that argument does not reach the unit suite: `ScopeDefinition`
 * validates an array and the provider binds a singleton, and neither touches a
 * connection. Sharing the Feature base would make those tests unrunnable without a
 * `pgvector/pgvector` server, which is a real cost — the config validation this suite
 * covers is exactly what an adopter debugs when their install will not boot.
 *
 * The default connection is pointed at a name that is deliberately not defined, rather
 * than left alone or aimed at a sound-alike SQLite one. Leaving it alone would not do:
 * `phpunit.xml` exports `DB_CONNECTION=pgsql` and the rest of the `DB_*` variables, and
 * CI's service container provisions a `testing` database from exactly those values — so
 * a unit test that quietly started querying would connect and pass there while failing
 * on a developer machine, which is the worst of both. Naming a connection that does not
 * exist makes that failure immediate and identical everywhere.
 */
abstract class UnitTestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [ScoutPostgresServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'unit-tests-have-no-database');
    }
}
