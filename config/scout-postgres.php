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
    | P0 scaffold: ScopeDefinition itself lands in P1. This key documents the shape
    | the migration and engine will consume.
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

];
