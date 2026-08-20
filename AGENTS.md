# AGENTS.md — core45/laravel-scout-postgres-hybrid

Agent-facing reference for integrating, configuring, or debugging this package. For adopter
prose and rationale see `README.md`; for the tenancy design invariants see
`docs/adr/0001-scope-abstraction-and-contracts.md`. A more detailed, skill-shaped version of
this document lives at `skills/using-scout-postgres-hybrid/SKILL.md`.

## What this is

A Laravel Scout engine for PostgreSQL that fuses weighted `tsvector` full-text search,
`pg_trgm` trigram similarity, and `pgvector` cosine similarity into one ranked result set via
reciprocal rank fusion (RRF), so a document that ranks moderately on all three branches beats
one that spikes on only one. One migration produces both a single-tenant and a multi-tenant
schema, driven entirely by config. Requires PHP 8.3+, Laravel 12/13, Scout 11, and PostgreSQL
14+ with the `vector`, `pg_trgm` and `unaccent` extensions.

## Integration checklist, in order

1. Implement `Core45\ScoutPostgres\Contracts\DocumentType` (`src/Contracts/DocumentType.php`) —
   usually a backed enum with `value()`, `modelClass()`, `isTranslatable()`.
2. Register it: `config/scout-postgres.php` → `'types' => [App\Enums\SearchableType::class]`.
   Ships empty; nothing is indexable until at least one type is registered.
3. Implement `Core45\ScoutPostgres\Contracts\SearchIndexable::toSearchDocuments(): array` on
   each indexable model, returning `list<SearchDocumentData>`
   (`src/DTOs/SearchDocumentData.php`, built via `SearchDocumentData::make(...)`).
4. Add `use Core45\ScoutPostgres\Concerns\SearchableWithoutSyncing;` to those models
   (`src/Concerns/SearchableWithoutSyncing.php`) — Scout's query side, none of its save-time
   observer.
5. Register the observer per model: `Product::observe(SearchDocumentObserver::class)`
   (`src/Observers/SearchDocumentObserver.php`), which dispatches
   `src/Jobs/SyncSearchDocumentJob.php` onto the queue named by `config('scout-postgres.queue')`.
6. Multi-tenant only: bind `Core45\ScoutPostgres\Contracts\ScopeResolver` and
   `Core45\ScoutPostgres\Contracts\ScopeRepository` (`src/Contracts/ScopeResolver.php`,
   `src/Contracts/ScopeRepository.php`) in a service provider, and set
   `scope.mode => 'column'` in `config/scout-postgres.php`. No default ships for either
   contract — an unbound resolver is meant to throw, not guess.
7. Optional: bind a real `Core45\ScoutPostgres\Contracts\EmbeddingProvider`
   (`src/Contracts/EmbeddingProvider.php`) to enable the semantic branch, then run
   `php artisan scout-postgres:reindex` (reindexes and backfills embeddings) or call
   `app(Core45\ScoutPostgres\Embedding\EmbeddingBackfill::class)->run($scope, limit: 1000)`
   directly.

## Traps

- **`toSearchDocuments()` returns MANY documents, one per locale** — not one flattened array
  like Scout's `toSearchableArray()`. Resolve each locale's text the way the app would render
  it (fallbacks included), because that governs findability per locale.
- **Returning `[]` means "delete every document row for this model."** It replaces
  `shouldBeSearchable()`; unpublishing and deleting converge on the same state.
- **Compare `DocumentType` instances by `value()`, never by `===` or `in_array` strict mode.**
  An interface is not an enum: a backed-enum adopter gets the same instance every call to
  `::cases()`, but nothing guarantees that for any `DocumentType` implementation, so identity
  comparison passes in tests and fails in production. This has already caused real bugs.
- **Vectors are NOT written by the indexer.** Indexing nulls the embedding whenever the text
  changes; only `Core45\ScoutPostgres\Embedding\EmbeddingBackfill::run()`
  (`src/Embedding/EmbeddingBackfill.php`) writes them. Until it runs, the semantic branch has
  no data and search is a two-branch (keyword + trigram) hybrid.
- **The default `EmbeddingProvider` is `NullEmbeddingProvider`** (`src/Embedding/NullEmbeddingProvider.php`)
  — `embed()` always returns `null`, `isReady()` is always `false`. Semantic search silently
  returns nothing until a real provider is bound.
- **`whereNotIn()` is rejected, not silently ignored.** Scout filters compile to jsonb
  containment (`PostgresDocumentEngine.php`), which has no negative form.
- **Integer (bigint) primary keys only.** `searchable_id` is `unsignedBigInteger` and ordering
  uses `array_position(?::bigint[], …)`. UUID/ULID models cannot be indexed (v0.1).
- **Source models must carry the scope column themselves in `column` mode** — hydration
  filters the source table, not just `search_documents`.
- **Source keys must be unique across scopes, not merely within one.** The identity index is
  `(searchable_type, searchable_id, locale)` and deliberately excludes the scope column; one
  model indexed under two tenants collides instead of producing two independent rows.

## Invariants — do not break these when editing the package itself

- **SC-1.** `ScopeResolver::current()` and `::normalize()` return `int` and never `null`;
  an unresolvable scope throws `Core45\ScoutPostgres\Exceptions\UnresolvableScope`. Never widen
  a query by dropping the scope predicate, and never force an empty result to cover the gap —
  both look identical to the caller ("no results") while being a cross-tenant leak or a silent
  outage respectively.
- **SC-2.** The DDL and every runtime consumer read one `Core45\ScoutPostgres\Scope\ScopeDefinition`
  singleton, hydrated once via `ScopeDefinition::fromConfig()`. Never introduce a second place
  that reads `config('scout-postgres.scope')` independently.
- **SC-3.** Cross-scope reads go only through `Core45\ScoutPostgres\Scope\CrossScope::platformWide(string $reason)`.
  Never call `withoutGlobalScopes()` (or an equivalent bypass) outside a method whose signature
  names `CrossScope` — a boolean flag is reachable by accident, a typed argument is not.
- **Scope predicates are repeated per branch on purpose.** Keyword, trigram and semantic each
  emit their own scope predicate in `PostgresSearchService` — defence in depth for tenant
  isolation. Do not refactor this into one shared outer `WHERE`.

## Diagnosing "search returns nothing"

Check in order:

1. Is the Scout connection actually PostgreSQL? `PostgresSearchService::available()`
   (`src/Search/PostgresSearchService.php`) gates on this.
2. Are `vector`, `pg_trgm` and `unaccent` installed (`CREATE EXTENSION IF NOT EXISTS …`)?
3. Is the model's `DocumentType` registered in `config('scout-postgres.types')`?
4. Did `toSearchDocuments()` actually return documents (not `[]`), and did the observer /
   `SyncSearchDocumentJob` run?
5. Is the locale right? Non-translatable types index under `DocumentType::LOCALE_ANY` (`'*'`,
   `src/Contracts/DocumentType.php`) — querying a specific locale against it returns nothing.
6. Semantic branch specifically: is a real `EmbeddingProvider` bound (not `NullEmbeddingProvider`),
   and has `EmbeddingBackfill::run()` (or `scout-postgres:reindex` without `--no-embeddings`)
   run since the last text change?

## Testing

The suite needs real PostgreSQL with the three extensions — no SQLite fallback, because
`tsvector`, `pg_trgm` and `pgvector` have no SQLite equivalent.

```bash
docker run --rm -d -p 5432:5432 \
  -e POSTGRES_DB=testing -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD=postgres \
  pgvector/pgvector:pg17

vendor/bin/pest --testsuite=Unit,Feature
vendor/bin/pest --testsuite=SingleTenant
```

Run as **two separate `pest` invocations**, not one: `RefreshDatabase` runs `migrate:fresh`
once per process, and the schema migration builds one table shape per run (scoped when
`scope.mode => 'column'`, unscoped when `'none'`). The `SingleTenant` suite needs its own
process to get a table actually built with `scope.mode => 'none'` — sharing a process with
`Unit,Feature` would leave single-tenant mode untested while the run still reported green.

Quality gates: `vendor/bin/pint --test` and `vendor/bin/phpstan analyse` (level 6 against `src/`).
