---
name: scout-postgres-development
description: Build and work with core45/laravel-scout-postgres-hybrid features including configuring the postgres Scout driver, implementing DocumentType and SearchIndexable, wiring the SearchDocumentObserver and sync job, binding multi-tenant scope contracts, wiring an EmbeddingProvider and running the embedding backfill, and diagnosing empty search results.
license: MIT
metadata:
  author: core45
---

# Scout Postgres Development

## Overview
Use core45/laravel-scout-postgres-hybrid to register a Laravel Scout `postgres` driver that
fuses weighted `tsvector` full-text search, `pg_trgm` trigram similarity, and `pgvector`
cosine similarity into one ranked result set via reciprocal rank fusion (RRF). One migration
produces both a single-tenant and a multi-tenant schema, selected entirely by config.

## When to Activate
- Activate when configuring the `postgres` Scout driver, `config/scout-postgres.php`, or
  registering `Core45\ScoutPostgres\` document types.
- Activate when code references `SearchableWithoutSyncing`, `toSearchDocuments()`,
  `PostgresSearchService`, `SearchIndexer`, `SearchDocumentObserver`, `SyncSearchDocumentJob`,
  `EmbeddingBackfill`, `EmbeddingProvider`, `ScopeResolver`, `ScopeRepository`, `CrossScope`,
  or the `scout-postgres:reindex` artisan command.
- Activate when implementing `DocumentType` or `SearchIndexable`, binding multi-tenant scope
  contracts, wiring a real `EmbeddingProvider`, or diagnosing why search returns nothing.

## Scope
- In scope: driver configuration, `DocumentType`/`SearchIndexable` implementations, the
  observer/job sync path, single- and multi-tenant scope wiring, embedding provider wiring
  and backfill, reconciliation, and troubleshooting empty results.
- Out of scope: writing a search engine from scratch, other Scout drivers, non-Laravel
  frameworks.

## Workflow
1. Identify the task (initial integration, adding an indexable model, multi-tenant scope
   wiring, embeddings, or diagnosing empty results).
2. Read `references/scout-postgres-guide.md` and focus on the relevant section.
3. Apply the patterns from the reference, keeping code minimal and Laravel-native.
4. Before editing package internals in `src/`, re-read the Invariants section of the
   reference guide — several rules exist specifically to prevent cross-tenant data leaks.

## Core Concepts

### Setup
Requires PHP 8.3+, Laravel 12/13, Scout 11, and PostgreSQL 14+ with the `vector`, `pg_trgm`
and `unaccent` extensions.

1. Implement `Core45\ScoutPostgres\Contracts\DocumentType` — usually a backed enum with
   `value()`, `modelClass()`, `isTranslatable()`.
2. Register it in `config('scout-postgres.types')`. Ships empty; nothing indexes until a
   type is registered.
3. Implement `Core45\ScoutPostgres\Contracts\SearchIndexable::toSearchDocuments(): array`
   on each indexable model, returning `list<SearchDocumentData>`.
4. Add `use Core45\ScoutPostgres\Concerns\SearchableWithoutSyncing;` to those models —
   Scout's query side without Scout's own save-time observer.
5. Register `Core45\ScoutPostgres\Observers\SearchDocumentObserver` per model; it dispatches
   `Core45\ScoutPostgres\Jobs\SyncSearchDocumentJob` onto `config('scout-postgres.queue')`.
6. Multi-tenant only: bind `ScopeResolver` and `ScopeRepository`, set
   `scope.mode => 'column'`.
7. Optional: bind a real `EmbeddingProvider`, then run `php artisan scout-postgres:reindex`
   or `EmbeddingBackfill::run($scope, limit: 1000)`.

### The highest-value traps
- One model's `toSearchDocuments()` returns MANY documents, one per locale.
- Returning `[]` means "delete every document row for this model."
- Compare `DocumentType` instances by `value()`, never `===` or strict `in_array`.
- Vectors are written only by `EmbeddingBackfill`, never by the indexer.
- The default `EmbeddingProvider` (`NullEmbeddingProvider`) is inert — semantic search
  returns nothing until a real provider is bound.
- A document whose `embedding_fingerprint` differs from the bound provider's is excluded
  from semantic results, even after the backfill has run once with a different provider.

See `references/scout-postgres-guide.md` for the full trap list, the scope invariants, and
the diagnostic checklist.

## Do and Don't

- **Do** register every `DocumentType` in `config('scout-postgres.types')` before expecting
  a model to be indexable.
- **Do** run `EmbeddingBackfill` (directly or via `scout-postgres:reindex`) after binding a
  real `EmbeddingProvider` — indexing alone never writes vectors.
- **Do** bind both `ScopeResolver` and `ScopeRepository` together for multi-tenant installs;
  neither has a default implementation.
- **Don't** compare `DocumentType` values with `===` or strict `in_array` — compare
  `value()` strings.
- **Don't** call `whereNotIn()` against Scout filters — it throws; filters compile to jsonb
  containment, which has no negative form.
- **Don't** call `withoutGlobalScopes()` (or an equivalent bypass) outside a method whose
  signature names `Core45\ScoutPostgres\Scope\CrossScope` — that is the only sanctioned
  cross-tenant read path.
- **Don't** assume a partially-selected model (`select()` omitting the scope column) is
  safe to reconcile — an absent scope attribute throws rather than being read as "no scope".
