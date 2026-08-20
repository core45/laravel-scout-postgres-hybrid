# laravel-scout-postgres-hybrid

A Laravel Scout engine for PostgreSQL that fuses three retrieval strategies into one ranked
result set:

- **weighted `tsvector` full-text search** — title and body carry different weights
- **`pg_trgm` similarity** — typo and diacritic tolerance, and the only thing that relates
  word forms in languages PostgreSQL ships no stemmer for
- **`pgvector` semantic similarity** — cosine distance over embeddings

fused with **reciprocal rank fusion**, so a document that ranks moderately in all three beats
one that spikes in a single branch.

## Why another Postgres Scout driver

Other drivers cover full-text search, some add `pg_trgm`. What is unmatched here is the
**third branch**: pgvector semantic similarity ranked *against* the other two by RRF, rather
than concatenated after them. Add to that single- and multi-tenant support from one migration,
which none of them offer at all.

It also has **no `pgvector/pgvector` PHP dependency** — the schema uses Laravel's native
`$table->vector(...)`, which shipped in `v11.25.0`.

> **On maturity.** The suite runs against real PostgreSQL 17 with pgvector on every supported
> PHP and Laravel version, in both scope modes, and the tenant-isolation tests are the point
> rather than an afterthought. It has not yet been proven by a production adopter. Read
> [Adopter requirements](#adopter-requirements) before committing to it.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- Laravel Scout 11
- PostgreSQL 14+ with `vector`, `pg_trgm` and `unaccent` installable

## Installation

```bash
composer require core45/laravel-scout-postgres-hybrid
```

```bash
php artisan vendor:publish --tag=scout-postgres-config
php artisan vendor:publish --tag=scout-postgres-migrations
```

Point Scout at the driver in `config/scout.php`, or set `SCOUT_DRIVER=postgres`.

### Extensions

The engine needs three PostgreSQL extensions. `CREATE EXTENSION` is a superuser operation, so
on managed PostgreSQL (RDS, Cloud SQL, Supabase) an ordinary application role cannot run it.

If your role **can** create extensions:

```bash
php artisan vendor:publish --tag=scout-postgres-extensions-migration
php artisan migrate
```

If it **cannot**, ask a superuser to run these once, then publish only the schema migration:

```sql
CREATE EXTENSION IF NOT EXISTS vector;
CREATE EXTENSION IF NOT EXISTS pg_trgm;
CREATE EXTENSION IF NOT EXISTS unaccent;
```

## Getting started

### 1. Describe your document types

The package does not know what your application indexes. Tell it, by implementing
`DocumentType` — an enum is the natural shape:

```php
use Core45\ScoutPostgres\Contracts\DocumentType;

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

    public function isTranslatable(): bool
    {
        return true;
    }
}
```

Register it in `config/scout-postgres.php`:

```php
'types' => [App\Enums\SearchableType::class],
```

`value()` is stored in the `searchable_type` column and is the join key for hydration, so it
must stay stable across deploys. Renaming it orphans every indexed row of that type.

### 2. Make your models indexable

```php
use Core45\ScoutPostgres\Concerns\SearchableWithoutSyncing;
use Core45\ScoutPostgres\Contracts\SearchIndexable;
use Core45\ScoutPostgres\DTOs\SearchDocumentData;

class Product extends Model implements SearchIndexable
{
    use SearchableWithoutSyncing;

    public function toSearchDocuments(): array
    {
        if (! $this->is_published) {
            return [];   // no documents means "delete this model's rows"
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
}
```

Two things differ from Scout's `toSearchableArray()`, and both are deliberate:

- **One model produces many documents**, one per locale, because translations live in
  per-locale rows rather than one flattened blob. Resolve each locale's text the way your
  storefront would actually render it, fallbacks included — a Polish-only product should stay
  findable on `/en` by its Polish name, exactly as it is browsable there.
- **Returning `[]` replaces `shouldBeSearchable()`.** The indexer reads it as "delete every row
  for this model", so unpublishing and deleting converge on the same state.

`SearchableWithoutSyncing` gives you Scout's query side without its model observer. Scout's
observer only sees a model's own save, which is less than a denormalised corpus needs — see
[Keeping the index current](#keeping-the-index-current).

### 3. Multi-tenant only: bind the scope contracts

Skip this entirely if you are single-tenant.

```php
use Core45\ScoutPostgres\Contracts\ScopeRepository;
use Core45\ScoutPostgres\Contracts\ScopeResolver;

class TenantScope implements ScopeResolver, ScopeRepository
{
    public function current(): int
    {
        return Tenant::current()?->id
            ?? throw UnresolvableScope::noAmbientScope();
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

Bind both in a service provider. The package ships **no default** for either: a stub that
resolved *some* scope is exactly the failure mode the isolation rules exist to prevent, so
their absence throws where a wrong guess would not.

### 4. Search

```php
Product::search('yoga mat')->get();
Product::search('yoga mat')->options(['scope' => $tenant->id])->paginate(20);
Product::search('yoga mat')->where('brand_id', 7)->whereIn('size', ['M', 'L'])->get();
```

Or reach the branches directly, which is what the Scout engine does internally:

```php
use Core45\ScoutPostgres\DTOs\SearchQuery;
use Core45\ScoutPostgres\Search\PostgresSearchService;

$service = PostgresSearchService::for($tenant->id);   // null when single-tenant

$query = SearchQuery::make(
    term: 'yoga mat',
    locale: 'en',
    types: [SearchableType::Product],
    limit: 20,
);

$results = $service->search($query);          // all three branches, RRF-fused
$results = $service->keyword($query);         // tsvector only
$results = $service->trigram($query);         // pg_trgm only
$results = $service->semantic($query);        // pgvector only

$models = $service->hydrate($results, SearchableType::Product);
```

Scout `where()` compiles to jsonb containment against the `filters` column, `whereIn()` to ORed
containments, and `>=` / `<=` to numeric bounds. `whereNotIn()` is rejected rather than
silently ignored: containment has no negative form.

## Keeping the index current

Register the observer for each indexable model:

```php
use Core45\ScoutPostgres\Observers\SearchDocumentObserver;

Product::observe(SearchDocumentObserver::class);
```

It dispatches a queued job that **re-reads the model** rather than carrying a serialised
payload, and treats save and delete as one reconcile — so the job cannot write a stale
document, and queue ordering stops mattering. Set the queue with `SEARCH_QUEUE`.

When a *contributor* changes rather than the model itself — a renamed brand restaling every
product that names it, a pivot `sync()` that fires no Eloquent event — dispatch a reconcile
yourself. The package gives you the primitive and stays out of your dependency graph, because
which models contribute to which documents is application knowledge:

```php
app(SearchIndexer::class)->reconcile($scope, SearchableType::Product, $productId);
```

Rebuild everything:

```bash
php artisan scout-postgres:reindex --scope=1 --prune
php artisan scout-postgres:reindex            # single-tenant
```

Suppress indexing during a bulk import, then reindex once:

```php
SearchIndexer::withoutIndexing(fn () => $importer->run());
```

## Semantic search

The semantic branch is **off until you bind an `EmbeddingProvider`**. The default one is inert,
so the package installs and works as a two-branch hybrid with no embedding infrastructure.

```php
use Core45\ScoutPostgres\Contracts\EmbeddingProvider;

class OpenAiEmbeddings implements EmbeddingProvider
{
    public function embed(string $text): ?array
    {
        return OpenAI::embeddings()
            ->create(['model' => 'text-embedding-3-small', 'input' => $text])
            ->embeddings[0]->embedding ?? null;
    }

    public function isReady(): bool
    {
        return config('services.openai.key') !== null;
    }

    public function fingerprint(): string
    {
        return 'openai:text-embedding-3-small';
    }
}
```

**Vectors are written by a backfill, not by the indexer.** Indexing happens synchronously on
every save; embedding is a paid, rate-limited network round-trip, and putting it on that path
would mean a provider outage blocks every model write in your application. So the indexer nulls
the vector whenever the text changes, and something else fills it in:

```bash
php artisan scout-postgres:reindex --scope=1        # reindexes, then embeds
php artisan scout-postgres:reindex --no-embeddings  # skip the embedding pass
```

```php
app(EmbeddingBackfill::class)->run($scope, limit: 1000);
```

Queue that after your sync job if you want vectors to appear without a reindex. Until something
runs it, the semantic branch has no data and the engine is a two-branch hybrid.

`fingerprint()` must change whenever the model does. It is stored beside every vector, and
vectors from two different models are not comparable — a distance between them is a
plausible-looking number that means nothing. A changed fingerprint marks rows stale instead.

Set `SEARCH_EMBEDDING_DIMENSIONS` to your model's width **before** migrating; the column is a
typed `vector(N)`.

There is deliberately **no ANN index** on the embedding column. pgvector post-filters `WHERE`
against the approximate candidate set, so a query scoped to one tenant and type can return
fewer rows than requested, or none, while matches exist. That is a correctness failure, not a
slow query.

## Single-tenant and multi-tenant

One migration produces both schemas, driven by `config/scout-postgres.php`:

```php
'scope' => ['mode' => 'none'],   // single tenant: no column, no predicate

'scope' => [                     // multi tenant
    'mode' => 'column',
    'column' => 'tenant_id',
    'table' => 'tenants',
    'foreign_key' => true,
    'on_delete' => 'cascade',
    'nullable' => false,
],
```

The array is hydrated once into a `ScopeDefinition` value object that the migration *and* every
query read, so a table built in one mode cannot be queried in the other. A mistyped mode throws
at boot rather than quietly becoming "search everything".

`mode => 'none'` is a configured state, not the absence of configuration. Where a scope column
**is** configured and no scope can be resolved, the engine throws. It never widens to an
unfiltered query and never returns an empty result to cover the gap — both look identical to
the caller, while being a cross-tenant leak and a silent outage respectively.

Platform-wide reads are legitimate but must be asked for by name:

```php
use Core45\ScoutPostgres\Scope\CrossScope;

app(SearchIndexStatistics::class)->total(
    CrossScope::platformWide('admin dashboard corpus size'),
);
```

A distinct type rather than a boolean flag, because a flag is reachable by accident and a type
is greppable and can be asserted on.

## Adopter requirements

Four assumptions the engine makes about your models. Each is a hard failure rather than a
degraded result if it does not hold.

- **Integer primary keys.** `searchable_id` is a `bigint` and ordering uses
  `array_position(?::bigint[], …)`. UUID and ULID models cannot be indexed.
- **Source models carry the scope column** under `mode => 'column'`, because hydration filters
  the source table as well as the document table.
- **Source keys are unique across scopes.** The identity index is
  `(searchable_type, searchable_id, locale)` and deliberately excludes the scope, so one model
  indexed under two tenants surfaces as a conflict rather than two silently divergent rows.
- **A resolvable scope, or none at all**, as described above.

The reasoning behind each is in
[`docs/adr/0001-scope-abstraction-and-contracts.md`](docs/adr/0001-scope-abstraction-and-contracts.md).

## Configuration

| Key | What it does |
| --- | --- |
| `scope` | Single- or multi-tenant, the column, and its foreign key |
| `types` | Your `DocumentType` classes |
| `hydration.strip_global_scopes` | Global scopes to strip when hydrating source models |
| `text_search` | Locale to PostgreSQL `regconfig` map, and the fallback |
| `trigram.word_similarity_threshold` | `SEARCH_TRIGRAM_THRESHOLD`, default 0.4 |
| `synonyms` | Query expansion pairs. Ships empty |
| `hybrid.k`, `hybrid.weights` | RRF tuning: `SEARCH_RRF_K`, `SEARCH_WEIGHT_KEYWORD`, `SEARCH_WEIGHT_SEMANTIC` |
| `vector.*` | Enable flag, dimensions, minimum similarity, cache TTLs |
| `max_body_length` | Body truncation, default 100 000 characters |
| `queue` | Where the sync job runs |

The trigram threshold uses `word_similarity`, not `similarity`. On `'joga mata do cwiczen'` the
query `jogi` scores 0.130 by `similarity` and 0.600 by `word_similarity`: `similarity()`
normalises over the union of both trigram sets, so a short query against a multi-word title is
diluted below any usable threshold.

## Testing

The suite requires real PostgreSQL with the three extensions. There is no SQLite fallback,
because `tsvector`, `pg_trgm` and `pgvector` have no SQLite equivalent and a green SQLite run
would prove nothing.

```bash
docker run --rm -d -p 5432:5432 \
  -e POSTGRES_DB=testing -e POSTGRES_USER=postgres -e POSTGRES_PASSWORD=postgres \
  pgvector/pgvector:pg17

vendor/bin/pest --testsuite=Unit,Feature
vendor/bin/pest --testsuite=SingleTenant
```

Two runs, not one: `RefreshDatabase` migrates once per process, so the single-tenant suite needs
its own to get a table built with `scope.mode => none`. A single green configuration says
nothing about an abstraction whose whole job is having two.

## Licence

MIT.
