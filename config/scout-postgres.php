<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Scope
    |--------------------------------------------------------------------------
    |
    | How the document corpus is partitioned between tenants.
    |
    |   'column' — every document row carries a scope column (a tenant/shop id),
    |              and every query filters on it. Multi-tenant hosts want this.
    |   'none'   — single-tenant. The column is not created, and no query filters
    |              on it.
    |
    | This array is *input*. At boot it is hydrated into a ScopeDefinition value
    | object, which is what the migration and the engine both read — one source of
    | truth for the DDL and the runtime, so a table built in one mode cannot be
    | queried in the other. See docs D3 and SC-2.
    |
    | Hydrated by ScopeDefinition::fromConfig() when the service provider registers.
    | An unrecognised mode, a 'column' mode with no column, or a foreign key with no
    | table throws there rather than degrading into an unscoped query later.
    |
    */

    'scope' => [
        'mode' => env('SCOUT_POSTGRES_SCOPE_MODE', 'none'),
        'column' => 'tenant_id',
        'table' => 'tenants',
        'foreign_key' => true,
        'on_delete' => 'cascade',
        'nullable' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Hydration
    |--------------------------------------------------------------------------
    |
    | Search returns keys; the models behind them are loaded from their own tables.
    | Those tables usually sit behind the host application's global scopes, and a
    | scope that throws with no bound tenant would break hydration on queues and in
    | console commands where the engine legitimately runs unbound.
    |
    | Name the global scopes to strip while hydrating. The default is empty: a
    | package cannot know these names, and stripping a scope the adopter did not
    | name would be the package widening a query on its own initiative. In the
    | host application the equivalent list is ['shop', 'shopAccess'].
    |
    | Isolation is not weakened by this, because it is not what enforces it: when
    | scope.mode is 'column' the engine applies its own scope predicate to the
    | source model's table (C3 — see the ADR: source models must carry the scope
    | column).
    |
    */

    'hydration' => [
        'strip_global_scopes' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Query embeddings are cached under a key built from this prefix, the scope, and
    | the embedding fingerprint. The prefix is configurable so two applications
    | sharing one cache store cannot collide (C7); the scope segment is emitted only
    | when scope.mode is 'column', so single-tenant installs get no empty segment.
    |
    */

    'cache' => [
        'prefix' => env('SCOUT_POSTGRES_CACHE_PREFIX', 'scout-postgres'),
    ],

];
