# core45/laravel-scout-postgres-hybrid Guide

A Laravel Scout engine for PostgreSQL. It fuses three retrieval branches into one ranked
result set:

- weighted `tsvector` full-text search (title and body carry different weights)
- `pg_trgm` similarity, for typo/diacritic tolerance and for languages PostgreSQL ships no
  stemmer for
- `pgvector` cosine similarity over embeddings

The branches are fused with reciprocal rank fusion (RRF): a document that ranks moderately in
all three beats one that spikes in a single branch. One migration produces both a
single-tenant and a multi-tenant schema, selected entirely by config — nothing else in the
package branches on tenancy mode.

Requires PHP 8.3+, Laravel 12/13, Scout 11, and PostgreSQL 14+ with the `vector`, `pg_trgm`
and `unaccent` extensions.

## Integration checklist

Follow in order; steps 6–7 are conditional.

1. **Implement `DocumentType`** (`src/Contracts/DocumentType.php`) — usually a backed enum.
   Required methods: `value(): string` (stored in `search_documents.searchable_type`, the join
   key for hydration — renaming it orphans every indexed row of that type), `modelClass():
   string` (the Eloquent class hydrated for hits), `isTranslatable(): bool`.

   ```php
   enum SearchableType: string implements DocumentType
   {
       case Product = 'product';
       case Post = 'post';

       public function value(): string { return $this->value; }

       public function modelClass(): string
       {
           return match ($this) {
               self::Product => Product::class,
               self::Post => Post::class,
           };
       }

       public function isTranslatable(): bool { return true; }
   }
   ```

2. **Register every type** in `config/scout-postgres.php`:

   ```php
   'types' => [App\Enums\SearchableType::class],
   ```

   Ships empty. Until at least one type is registered the engine indexes nothing.

3. **Implement `SearchIndexable::toSearchDocuments(): array`** (`src/Contracts/SearchIndexable.php`)
   on each indexable model, returning `list<SearchDocumentData>`
   (`src/DTOs/SearchDocumentData.php`, built via `SearchDocumentData::make(...)`):

   ```php
   public function toSearchDocuments(): array
   {
       if (! $this->is_published) {
           return [];   // deletes every row for this model
       }

       return collect(['en', 'pl'])->map(fn (string $locale) => SearchDocumentData::make(
           searchableType: SearchableType::Product,
           searchableId: (int) $this->getKey(),
           locale: $locale,
           title: $this->translateOrDefault($locale)->name,
           body: $this->translateOrDefault($locale)->description ?? '',
           filters: ['brand_id' => $this->brand_id, 'in_stock' => $this->in_stock],
       ))->all();
   }
   ```

4. **Add the trait**: `use Core45\ScoutPostgres\Concerns\SearchableWithoutSyncing;`
   (`src/Concerns/SearchableWithoutSyncing.php`). It gives Scout's query side
   (`Model::search()`) without Scout's model observer — this package's own observer replaces
   it, because Scout's observer only sees a model's own save, which is less than a
   denormalised, per-locale corpus needs.

5. **Register `SearchDocumentObserver`** per indexable model:

   ```php
   Product::observe(Core45\ScoutPostgres\Observers\SearchDocumentObserver::class);
   ```

   (`src/Observers/SearchDocumentObserver.php`, `implements ShouldHandleEventsAfterCommit`,
   handles `saved`/`deleted`/`restored`). It dispatches
   `Core45\ScoutPostgres\Jobs\SyncSearchDocumentJob` (`src/Jobs/SyncSearchDocumentJob.php`) onto
   the queue named by `config('scout-postgres.queue')` (env `SEARCH_QUEUE`). The job re-reads
   the model rather than carrying a serialised payload, and treats save and delete as one
   reconcile, so it cannot write a stale document and queue ordering stops mattering.

   When a *contributor* changes rather than the model itself — a renamed brand restaling every
   product that names it, a pivot `sync()` firing no Eloquent event — dispatch a reconcile by
   hand: `app(Core45\ScoutPostgres\Search\SearchIndexer::class)->reconcile($scope, $type, $id)`.
   Which models contribute to which documents is application knowledge the package does not
   have.

6. **Multi-tenant only — bind the scope contracts.** Skip entirely if single-tenant.

   - `Core45\ScoutPostgres\Contracts\ScopeResolver` (`src/Contracts/ScopeResolver.php`):
     `current(): int` (the scope in effect now; throws `UnresolvableScope` if none is bound)
     and `normalize(mixed $scope): int` (derive a scope key from whatever the caller holds).
   - `Core45\ScoutPostgres\Contracts\ScopeRepository` (`src/Contracts/ScopeRepository.php`):
     `exists(int $scope): bool` — implementations MUST bypass the host's own tenant global
     scopes, because reconciliation runs unbound on queues and in console commands, and a
     scoped lookup would report every scope missing.

   ```php
   class TenantScope implements ScopeResolver, ScopeRepository
   {
       public function current(): int
       {
           return Tenant::current()?->id ?? throw UnresolvableScope::noAmbientScope();
       }

       public function normalize(mixed $scope): int
       {
           return $scope instanceof Tenant ? $scope->id : (int) $scope;
       }

       public function exists(int $scope): bool
       {
           return Tenant::query()->whereKey($scope)->exists();
       }
   }
   ```

   Bind both in a service provider. No default ships for either — a stub that resolved *some*
   scope is exactly the failure mode the isolation rules exist to prevent. Set
   `scope.mode => 'column'` (plus `column`, `table`, `foreign_key`, `on_delete`, `nullable`) in
   `config/scout-postgres.php`; `'mode' => 'none'` is the single-tenant default.

   Scope values are **validated, not cast**: a numeric-looking string reaching
   `ScopeResolver::normalize()` throws rather than being silently cast to an integer. This
   closed a real defect in 1.0.0 where `'1-not-authorized'` cast straight to `1` and returned
   tenant 1's documents. `scout-postgres:reindex --scope` validates its argument the same way.

7. **Optional — wire an `EmbeddingProvider`** (`src/Contracts/EmbeddingProvider.php`):
   `embed(string $text): ?array`, `isReady(): bool`, `fingerprint(): non-empty-string`. The
   bound default, `Core45\ScoutPostgres\Embedding\NullEmbeddingProvider`
   (`src/Embedding/NullEmbeddingProvider.php`), always returns `null` from `embed()` and
   `false` from `isReady()` — the package installs and works as a two-branch (keyword +
   trigram) hybrid with no embedding infrastructure configured. Then run the backfill (see
   "Semantic search is off until you run the backfill" below).

   Set `SEARCH_EMBEDDING_DIMENSIONS` to the provider's vector width **before** migrating — the
   `search_documents.embedding` column is a typed `vector(N)`.

## Traps

- **`toSearchDocuments()` returns MANY documents per model, one per locale.** Not one
  flattened array like Scout's `toSearchableArray()`. Resolve each locale's text the way the
  storefront would actually render it, fallbacks included, so a Polish-only product stays
  findable on `/en` by its Polish name.
- **Returning `[]` means "delete this model's rows."** It replaces `shouldBeSearchable()`;
  unpublishing and deleting converge on the same state.
- **Compare `DocumentType` instances by `value()`, never `===` or strict `in_array`.** An
  interface is not an enum: a backed-enum adopter's `::cases()` happens to return the same
  instance every call, but nothing in the contract guarantees that for any implementation.
  Code that compares by identity passes against enum fixtures in tests and fails in production
  against ordinary objects. This has already caused real bugs.
- **Semantic search is off until you run the backfill.** Indexing nulls the embedding whenever
  a document's text changes — it never writes a vector itself, because embedding is a paid,
  rate-limited network round-trip and putting it on the synchronous save path would mean a
  provider outage blocks every model write. Only
  `Core45\ScoutPostgres\Embedding\EmbeddingBackfill::run(?int $scope, int $limit = 500): int`
  (`src/Embedding/EmbeddingBackfill.php`) writes vectors:

  ```bash
  php artisan scout-postgres:reindex --scope=1        # reindexes, then embeds
  php artisan scout-postgres:reindex --no-embeddings  # skip the embedding pass
  ```

  or `app(EmbeddingBackfill::class)->run($scope, limit: 1000)` directly. Until something runs
  it, the semantic branch has no data and the engine is a two-branch hybrid — silently, with
  no error.
- **The default provider is inert.** `NullEmbeddingProvider` is bound unless the adopter binds
  their own; semantic search returns nothing until a real provider is bound, even after the
  backfill runs.
- **A document whose `embedding_fingerprint` doesn't match the bound provider is excluded
  from semantic results.** `EmbeddingProvider::fingerprint()` identifies the provider *and*
  the model; `SemanticSearchService` filters on `embedding_fingerprint = ?`
  (`src/Search/SemanticSearchService.php`), so switching providers or model versions silently
  drops every previously-embedded document from the semantic branch until the backfill runs
  again under the new fingerprint. `embed()` returning `null` for one document does not fail
  the whole search — that document is simply excluded from the semantic branch.
- **`whereNotIn()` is rejected, not silently ignored.** Scout `where()`/`whereIn()` compile to
  jsonb containment (and ORed containment) against the `filters` column in
  `PostgresDocumentEngine` (`src/Search/PostgresDocumentEngine.php`); containment has no
  negative form, so `whereNotIn()` throws rather than producing a wrong result.
- **Integer (bigint) primary keys only.** `searchable_id` is `unsignedBigInteger`, and ordering
  uses `array_position(?::bigint[], …)`. UUID and ULID models cannot be indexed in v0.1.
- **Source models must carry the scope column themselves, under `mode => 'column'`.**
  Hydration filters the *source* model's table as well as `search_documents` — see C3 in the
  ADR. A model loaded via a partial `select()` that omits the scope column throws on
  reconciliation rather than being treated as scopeless — an absent scope attribute is an
  error, while a genuinely empty scope value is a different, valid case and is skipped.
- **Source keys must be unique across scopes, not merely within one tenant.** The identity
  index on `search_documents` is `(searchable_type, searchable_id, locale)` and deliberately
  excludes the scope column in both modes (C8), so one model indexed under two tenants
  produces a genuine conflict rather than two independently-tracked rows.

## Invariants — do not break these when editing the package itself

Drawn from `docs/adr/0001-scope-abstraction-and-contracts.md` and `CONTRIBUTING.md`. These are
load-bearing tenant-isolation guarantees, not style preferences.

- **SC-1 — an unresolvable scope throws, never widens, never empties.**
  `ScopeResolver::current()` / `::normalize()` (`src/Contracts/ScopeResolver.php`) return `int`
  and never `null`; failure is `Core45\ScoutPostgres\Exceptions\UnresolvableScope`. A query
  with the scope predicate dropped is a cross-tenant leak; a query forced to match nothing is a
  silent outage. Both look identical to the caller as "no results", which is exactly why
  neither is acceptable. `ScopeDefinition::requireColumn()` applies the same discipline one
  level down. Scope resolution happens before the PostgreSQL availability check, so degrading
  to empty results off a non-PostgreSQL connection never doubles as a way to skip scope
  resolution.
- **SC-2 — one `ScopeDefinition` for DDL and runtime.** `ScopeDefinition::fromConfig()`
  (`src/Scope/ScopeDefinition.php`) hydrates once into a singleton, bound in the service
  provider, that both the migration and every query-building consumer read. Never add a second
  code path that reads `config('scout-postgres.scope')` directly — agreement between schema
  and query is structural (one object, one source) rather than something separately validated.
- **SC-3 — cross-scope reads go only through `CrossScope`.** Platform-wide reads
  (`Core45\ScoutPostgres\Search\SearchIndexStatistics`) are legitimate but must be requested by
  constructing `Core45\ScoutPostgres\Scope\CrossScope::platformWide(string $reason)`
  (`src/Scope/CrossScope.php`) and passing it to a method whose signature names the type. Never
  call `withoutGlobalScopes()` (or an equivalent bypass) anywhere else in the package — a
  boolean flag on an existing method is reachable by accident; constructing this type is not.
- **Scope predicates are repeated per branch, on purpose.** Each of the three search branches
  in `PostgresSearchService` (`src/Search/PostgresSearchService.php` — `keyword()`, `trigram()`,
  `semantic()`) emits its own scope predicate. Do not collapse this into one shared outer
  `WHERE`: the repetition is defence in depth for tenant isolation.
- **Documents are purged when their scope no longer exists.** Reconciliation does not assume
  `ON DELETE CASCADE` has removed them — the package also supports no foreign key at all and
  `ON DELETE SET NULL`, and both leave orphaned or unreachable rows if reconciliation skips the
  explicit purge.

## Diagnosing "search returns nothing"

Work through in order:

1. **Is the Scout connection PostgreSQL?** `PostgresSearchService::available()` gates the
   engine on this; a non-Postgres connection means the engine never ran.
2. **Are the three extensions installed?** `vector`, `pg_trgm`, `unaccent` — `CREATE EXTENSION
   IF NOT EXISTS <name>;` needs a superuser role, so on managed Postgres (RDS, Cloud SQL,
   Supabase) an application role may not have been able to run it.
3. **Is a `DocumentType` registered** for this model in `config('scout-postgres.types')`?
4. **Did `toSearchDocuments()` actually return documents** (not `[]`), and did the observer /
   `SyncSearchDocumentJob` run to completion on the queue named by `config('scout-postgres.queue')`?
5. **Is the locale right?** Non-translatable types are indexed once under
   `DocumentType::LOCALE_ANY` (the constant `'*'`); querying a specific locale against a
   non-translatable type's rows returns nothing because the row lives under `'*'`.
6. **Semantic branch specifically:**
   - Is a real `EmbeddingProvider` bound (not `NullEmbeddingProvider`)?
   - Has `EmbeddingBackfill::run()` (or `scout-postgres:reindex` without `--no-embeddings`) run
     since the text last changed? Indexing nulls the vector on every text change; nothing else
     fills it back in.
   - Does the stored `embedding_fingerprint` match the bound provider's `fingerprint()`? A
     provider or model change silently excludes previously-embedded documents until they are
     re-backfilled.

## Testing the package

The suite requires real PostgreSQL with `vector`, `pg_trgm` and `unaccent` — there is no
SQLite fallback, because none of those three have a SQLite equivalent, so a green SQLite run
would prove nothing.

```bash
docker run --rm -d -p 5432:5432 \
  -e POSTGRES_DB=testing -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD=postgres \
  pgvector/pgvector:pg17

vendor/bin/pest --testsuite=Unit,Feature
vendor/bin/pest --testsuite=SingleTenant
```

**Two separate `pest` invocations, never one process.** `RefreshDatabase` runs `migrate:fresh`
once per process, and the schema migration builds one table shape per run — scoped when
`scope.mode => 'column'`, unscoped when `'none'`. The `SingleTenant` suite needs its own
process to get a table actually built with `scope.mode => 'none'`; sharing a process with
`Unit,Feature` would leave it querying the already-scoped table, and single-tenant mode would
go untested while the run still reported green.

Quality gates before committing: `vendor/bin/pint --test` and `vendor/bin/phpstan analyse`
(level 6 against `src/`).
