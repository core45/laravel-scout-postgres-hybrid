# Contributing

## Running the suite

The suite requires a real PostgreSQL instance carrying the `vector`, `pg_trgm` and
`unaccent` extensions. There is no SQLite fallback, and that is deliberate: `tsvector`,
`pg_trgm` and `pgvector` have no SQLite equivalent, so a green SQLite run would prove
nothing about the engine.

Start a database with the extensions available (from the README):

```bash
docker run --rm -d -p 5432:5432 \
  -e POSTGRES_DB=testing -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD=postgres \
  pgvector/pgvector:pg17
```

Then run the suite as two separate `pest` invocations, exactly as CI does
(`.github/workflows/tests.yml`):

```bash
vendor/bin/pest --testsuite=Unit,Feature
vendor/bin/pest --testsuite=SingleTenant
```

These cannot share one process. `RefreshDatabase` runs `migrate:fresh` once per process,
and the schema migration builds one table shape per run — the scoped table when
`scope.mode => 'column'`, the unscoped table when `scope.mode => 'none'`. The
`SingleTenant` suite needs its own process to get a table actually built with
`scope.mode => 'none'`; sharing a process with `Unit,Feature` would leave it querying the
scoped table that suite already built, and the single-tenant mode would go untested while
the run still reported green.

## Quality gates

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

Pint enforces the Laravel preset plus `declare_strict_types` on every file (`pint.json`).
PHPStan runs at level 6 against `src/` (`phpstan.neon`).

## Design rules

Drawn from `docs/adr/0001-scope-abstraction-and-contracts.md`. These are load-bearing,
not style preferences — do not "clean them up" in a refactor.

- **Scope predicates are repeated per branch, on purpose.** Each of the three search
  branches (keyword, trigram, semantic) emits its own scope predicate. Do not refactor
  this into a single outer `WHERE` shared across branches: the repetition is defence in
  depth for tenant isolation, and collapsing it removes that.
- **An unresolvable scope must throw, never widen or empty out.** `ScopeResolver` and
  `ScopeDefinition::requireColumn()` throw rather than return `null` or a sentinel. A
  query with the scope predicate silently dropped is a cross-tenant leak; a query forced
  to match nothing is a silent outage. Both look identical to the caller as "no results",
  which is exactly why neither is acceptable.
- **Cross-scope reads go only through `CrossScope`.** Platform-wide reads are a genuine
  requirement (a count across every tenant, for example), but they must be reached only
  by constructing `Core45\ScoutPostgres\Scope\CrossScope::platformWide($reason)` and
  passing it to a method whose signature names the type. Never call
  `withoutGlobalScopes()` (or an equivalent bypass) from anywhere else in the package —
  a boolean flag on an existing method is reachable by accident; constructing this type
  is not.
- **Compare `DocumentType` instances by `value()`, never by object identity.** An
  adopter whose types are a backed enum gets the same instance every time `::cases()`
  runs; one whose types are ordinary objects does not. Code that compares with `===`
  would pass in a test suite built against enum fixtures and fail in production against
  ordinary objects.

Keep changes scoped and the commit message honest about what was and was not verified
locally — see the existing commit history for the expected level of detail.
