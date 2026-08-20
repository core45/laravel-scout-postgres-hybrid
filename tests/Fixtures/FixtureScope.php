<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Tests\Fixtures;

use Core45\ScoutPostgres\Contracts\ScopeRepository;
use Core45\ScoutPostgres\Contracts\ScopeResolver;
use Core45\ScoutPostgres\Exceptions\UnresolvableScope;
use Core45\ScoutPostgres\Tests\Fixtures\Models\Tenant;

/**
 * The adopter's half of the scope contracts, as a test would supply it.
 *
 * Both interfaces on one class because the suite needs both bound together and
 * nothing in the package requires them separated. It resolves from an explicitly
 * set value rather than any ambient state, so a test that forgets to bind a scope
 * gets the throw SC-1 promises instead of a stale one from a previous test.
 */
final class FixtureScope implements ScopeRepository, ScopeResolver
{
    private static ?int $current = null;

    public static function bind(?int $scope): void
    {
        self::$current = $scope;
    }

    public function current(): int
    {
        if (self::$current === null) {
            throw UnresolvableScope::noAmbientScope();
        }

        return self::$current;
    }

    public function normalize(mixed $scope): int
    {
        if ($scope instanceof Tenant) {
            return (int) $scope->getKey();
        }

        if (is_numeric($scope)) {
            return (int) $scope;
        }

        throw UnresolvableScope::cannotNormalize($scope);
    }

    public function exists(int $scope): bool
    {
        return Tenant::query()->whereKey($scope)->exists();
    }
}
