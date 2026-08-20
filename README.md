# laravel-scout-postgres-hybrid

A Laravel Scout engine for PostgreSQL that fuses three retrieval strategies into one ranked
result set:

- **weighted `tsvector` full-text search** — title and body carry different weights
- **`pg_trgm` similarity** — typo and inflection tolerance, and the only thing that relates
  word forms in languages PostgreSQL ships no stemmer for
- **`pgvector` semantic similarity** — cosine distance over embeddings

fused with **reciprocal rank fusion**, so a document that ranks moderately in all three beats
one that spikes in a single branch.

> **Status: pre-alpha, P1.** No engine code is implemented yet. This repository currently
> contains the CI scaffold, the extension migration, and the contracts the engine will be
> built against. See the phase plan before relying on anything here.

## Why another Postgres Scout driver

Existing drivers each cover one strategy. This one covers all three in a single engine and a
single index, so keyword and semantic results are ranked against each other rather than
concatenated.

It also has **no `pgvector/pgvector` PHP dependency** — the schema uses Laravel's native
`$table->vector(...)`, which shipped in `v11.25.0`.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- Laravel Scout 11
- PostgreSQL 14+ with `vector`, `pg_trgm` and `unaccent` installable

## Installation

```bash
composer require core45/laravel-scout-postgres-hybrid
```

Publish the config:

```bash
php artisan vendor:publish --tag=scout-postgres-config
```

### Extensions

The engine needs three PostgreSQL extensions. `CREATE EXTENSION` is a superuser operation, so
on managed PostgreSQL (RDS, Cloud SQL, Supabase) an ordinary application role cannot run it.

If your role **can** create extensions:

```bash
php artisan vendor:publish --tag=scout-postgres-extensions-migration
php artisan migrate
```

If it **cannot**, ask a superuser to run the statements below once, then skip that tag and
publish only `scout-postgres-migrations`:

```sql
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;
```

## Adopter requirements

Beyond the versions above, the engine makes four assumptions about the models you index. They
are requirements rather than settings in v0.1, and each is a hard failure rather than a degraded
result if it does not hold.

- **Integer primary keys.** `searchable_id` is a `bigint`, and result ordering is built with
  `array_position(?::bigint[], …)`. Models keyed by UUID or ULID cannot be indexed. Deriving the
  cast from the model's key type is deferred to v0.2.
- **Source models carry the scope column.** Under `mode => 'column'`, hydration filters the
  source model's own table as well as the document table, so every indexed model needs the scope
  column on its own table.
- **Source keys are unique across scopes.** The identity index is
  `(searchable_type, searchable_id, locale)` and deliberately excludes the scope, so that one
  model indexed under two tenants surfaces as a conflict rather than as two silently divergent
  rows. If your primary keys restart per tenant, v0.1 rejects the second tenant's rows.
- **A resolvable scope, or none at all.** `mode => 'none'` is a configured state, not the absence
  of one. Where a scope column is configured and no scope can be resolved, the engine throws. It
  never widens to an unfiltered query and never returns an empty result set to cover the gap.

The reasoning behind each, and the couplings they come from, is in
[`docs/adr/0001-scope-abstraction-and-contracts.md`](docs/adr/0001-scope-abstraction-and-contracts.md).

## Single-tenant and multi-tenant

The document corpus can be partitioned by a scope column or not partitioned at all, set in
`config/scout-postgres.php`:

```php
'scope' => ['mode' => 'none'],                                    // single tenant
'scope' => ['mode' => 'column', 'column' => 'tenant_id', ...],    // multi tenant
```

One migration produces both schemas, and the same configuration drives the runtime queries, so
a table built in one mode cannot be queried in the other.

## Testing

The test suite requires a real PostgreSQL with the three extensions — there is no SQLite
fallback, because `tsvector`, `pg_trgm` and `pgvector` have no SQLite equivalent and a green
SQLite run would prove nothing.

```bash
docker run --rm -d -p 5432:5432 \
  -e POSTGRES_DB=testing -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD=postgres \
  pgvector/pgvector:pg17

vendor/bin/pest
```

## Licence

MIT.
