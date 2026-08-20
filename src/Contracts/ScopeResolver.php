<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Contracts;

use Core45\ScoutPostgres\Exceptions\UnresolvableScope;

/**
 * Turns the host application's notion of "the current tenant" into a scope key.
 *
 * The package never knows what a tenant is. In the host application it is a `Shop`
 * bound into a context middleware; elsewhere it may be a subdomain, a column on the
 * authenticated user, or a value pushed onto a queue job. All the engine needs is
 * the key that goes in `search_documents.<scope column>`.
 *
 * SC-1 shapes both methods: neither returns null and neither returns a sentinel.
 * An unresolvable scope throws, because the only alternatives — a query with the
 * scope predicate dropped, or one forced to match nothing — are respectively a
 * cross-tenant leak and a silent outage, and both look like "no results" to the
 * caller. Implementations must not catch their own failures to satisfy the return
 * type.
 *
 * C4 (v0.1): scope keys are integers. `search_documents.<scope column>` is
 * `unsignedBigInteger` and the migration is not configurable in this respect.
 *
 * Not called at all when `ScopeDefinition::isScoped()` is false — single-tenant
 * installs need no implementation bound.
 */
interface ScopeResolver
{
    /**
     * The scope in effect right now.
     *
     * @throws UnresolvableScope when no scope is bound
     */
    public function current(): int;

    /**
     * Derive a scope key from whatever the caller holds — a tenant model, its key,
     * or any host type this resolver understands.
     *
     * Mirrors `PostgresSearchService::for(Shop|int $shop)`: callers that already know
     * the tenant pass it explicitly, and a worker reused across tenants must be able
     * to pin one write to one scope without touching ambient state.
     *
     * @throws UnresolvableScope when `$scope` denotes no scope this resolver knows
     */
    public function normalize(mixed $scope): int;
}
