<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Search;

use Core45\ScoutPostgres\Contracts\DocumentType;
use Core45\ScoutPostgres\Contracts\DocumentTypeRegistry;
use Core45\ScoutPostgres\Contracts\ScopeResolver;
use Core45\ScoutPostgres\Contracts\SearchIndexable;
use Core45\ScoutPostgres\DTOs\SearchQuery;
use Core45\ScoutPostgres\DTOs\SearchResults;
use Core45\ScoutPostgres\Exceptions\UnresolvableScope;
use Core45\ScoutPostgres\Scope\ScopeDefinition;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;
use Laravel\Scout\Builder;
use Laravel\Scout\Contracts\PaginatesEloquentModelsUsingDatabase;
use Laravel\Scout\Engines\Engine;
use LogicException;

/**
 * Scout engine backed by the `search_documents` corpus.
 *
 * Scout is the abstraction; this class is the implementation behind it. Call
 * sites say `Model::search(...)`, so moving to another engine is a `SCOUT_DRIVER`
 * change rather than a rewrite. Everything the corpus offers — weighted tsvector
 * full text, `pg_trgm` typo and diacritic tolerance, `pgvector` semantic
 * similarity, fused with reciprocal rank fusion — is reached through
 * {@see PostgresSearchService}.
 *
 * ## Scope separation
 *
 * A Scout builder carries no scope, so isolation is this class's first
 * responsibility rather than a caller's. Four independent layers, none of which
 * may be the only one:
 *
 * 1. This engine never composes SQL. Every search goes through
 *    {@see PostgresSearchService::for()}, which repeats the scope predicate
 *    inside every query branch.
 * 2. {@see self::requireScope()} fails closed. It never falls back to an
 *    unfiltered query, and it never returns an empty result to paper over a
 *    missing scope — a silent empty is what every quiet failure in this stack
 *    has looked like.
 * 3. Hydration is pinned separately, by
 *    {@see PostgresSearchService::hydrateQuery()}, which strips the configured
 *    global scopes and re-adds the scope predicate explicitly. That matters
 *    because a host's own tenant scope may be inert on some routes.
 * 4. A caller cannot widen the scope. Scout `where()` clauses compile to jsonb
 *    containment against the `filters` column, while the scope is a real column,
 *    so `->where('tenant_id', $other)` narrows to nothing rather than crossing
 *    over.
 *
 * Layer 2 has one legitimate exception: when `scope.mode` is `none` there is no
 * scope to resolve and `requireScope()` returns null by design. That is a
 * configured state, not a missing one.
 *
 * ## Writes
 *
 * Scout's own model observer is disabled by this package's service provider. The
 * write path belongs to the sync job, which reconciles rather than pushing a
 * serialised payload, treats save and delete as one operation, and can fan out to
 * contributor models Scout's observer cannot see.
 *
 * So `update()` and `delete()` here are reached only by an explicit
 * `->searchable()` / `->unsearchable()` call, `scout:import` or `scout:flush`.
 * They delegate to {@see SearchIndexer} so those commands still do the right
 * thing.
 *
 * ## Degradation
 *
 * On any non-PostgreSQL connection this engine returns empty results and performs
 * no writes, matching `SearchIndexer::enabled()`. This is deliberate degradation,
 * not a silent scope failure: an unresolvable scope still throws.
 */
final class PostgresDocumentEngine extends Engine implements PaginatesEloquentModelsUsingDatabase
{
    /**
     * Hits fetched before hydration when the caller is paginating.
     *
     * Ranked search has no OFFSET — every branch runs `LIMIT` and the fusion
     * happens above that, so "page 40" is not expressible in the query. The
     * engine instead searches once to a generous cap and lets Eloquent paginate
     * the hydrated builder, where the `array_position()` ordering survives the
     * paginator's own LIMIT/OFFSET rewrite.
     *
     * The bound to accept: results beyond this cap are unreachable by paging.
     */
    private const RESULT_CAP = 500;

    /**
     * Default hit count when the caller set no limit.
     */
    private const DEFAULT_LIMIT = 20;

    public function __construct(
        private readonly ScopeDefinition $scope,
        private readonly DocumentTypeRegistry $types,
        private readonly ?ScopeResolver $resolver = null,
    ) {}

    /**
     * Reconcile the given models' documents.
     *
     * Reached only by an explicit `->searchable()` or `scout:import`; the
     * automatic path is this package's observer.
     *
     * @param  EloquentCollection<int, Model>  $models
     */
    public function update($models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $indexer = app(SearchIndexer::class);

        foreach ($models as $model) {
            if ($model instanceof SearchIndexable) {
                $indexer->reconcileModel($model);
            }
        }
    }

    /**
     * Remove the given models' documents, every locale row.
     *
     * @param  EloquentCollection<int, Model>  $models
     */
    public function delete($models): void
    {
        if ($models->isEmpty()) {
            return;
        }

        $indexer = app(SearchIndexer::class);

        foreach ($models as $model) {
            $scope = $this->scopeOf($model);

            // A scoped corpus with no scope on the model means the row cannot be
            // addressed: skipping is right, because purging without the predicate
            // would delete every scope's copy of that key.
            if ($this->scope->isScoped() && $scope === null) {
                continue;
            }

            $indexer->purge($scope, $this->types->forModel($model), (int) $model->getKey());
        }
    }

    /**
     * @param  Builder<covariant Model>  $builder
     * @return SearchResults
     */
    public function search(Builder $builder)
    {
        return $this->execute($builder, $this->limitFor($builder));
    }

    /**
     * Only reached through `paginateRaw()` / `simplePaginateRaw()`; the ordinary
     * paginators go through {@see self::paginateUsingDatabase()}.
     *
     * @param  int  $perPage
     * @param  int  $page
     * @param  Builder<covariant Model>  $builder
     * @return SearchResults
     */
    public function paginate(Builder $builder, $perPage, $page)
    {
        return $this->execute($builder, min(self::RESULT_CAP, (int) $perPage * (int) $page));
    }

    /**
     * @param  int  $perPage
     * @param  string  $pageName
     * @param  int  $page
     * @param  Builder<covariant Model>  $builder
     * @return LengthAwarePaginator<int, Model>
     */
    public function paginateUsingDatabase(Builder $builder, $perPage, $pageName, $page): LengthAwarePaginator
    {
        return $this->hydrationQuery($builder)
            ->paginate((int) $perPage, ['*'], $pageName, (int) $page);
    }

    /**
     * @param  int  $perPage
     * @param  string  $pageName
     * @param  int  $page
     * @param  Builder<covariant Model>  $builder
     * @return Paginator<int, Model>
     */
    public function simplePaginateUsingDatabase(Builder $builder, $perPage, $pageName, $page): Paginator
    {
        return $this->hydrationQuery($builder)
            ->simplePaginate((int) $perPage, ['*'], $pageName, (int) $page);
    }

    /**
     * @param  SearchResults  $results
     * @return Collection<int, int>
     */
    public function mapIds($results)
    {
        return collect($results->hits)->map(fn ($hit): int => $hit->searchableId)->unique()->values();
    }

    /**
     * @param  SearchResults  $results
     * @param  Model  $model
     * @param  Builder<covariant Model>  $builder
     * @return EloquentCollection<int, Model>
     */
    public function map(Builder $builder, $results, $model)
    {
        if ($results->isEmpty()) {
            return $model->newCollection();
        }

        [$service, $type] = $this->context($builder);

        $query = $service->hydrateQuery($results, $type);

        if ($builder->queryCallback !== null) {
            ($builder->queryCallback)($query);
        }

        $this->applyOrders($builder, $query);

        /** @var EloquentCollection<int, Model> $models */
        $models = $query->get();

        return $models;
    }

    /**
     * @param  SearchResults  $results
     * @param  Model  $model
     * @param  Builder<covariant Model>  $builder
     * @return LazyCollection<int, Model>
     */
    public function lazyMap(Builder $builder, $results, $model)
    {
        if ($results->isEmpty()) {
            return LazyCollection::make();
        }

        [$service, $type] = $this->context($builder);

        $query = $service->hydrateQuery($results, $type);

        if ($builder->queryCallback !== null) {
            ($builder->queryCallback)($query);
        }

        $this->applyOrders($builder, $query);

        return $query->cursor();
    }

    /**
     * @param  SearchResults  $results
     */
    public function getTotalCount($results): int
    {
        return $results->count();
    }

    /**
     * Remove every document of one model's type, within one scope.
     *
     * Scout's contract is per model class; the corpus is per (scope, type), so a
     * scope has to be resolvable. It fails closed for the same reason a search
     * does — a flush that quietly hit no rows reads exactly like a flush that
     * worked.
     *
     * @param  Model  $model
     */
    public function flush($model): void
    {
        if (! SearchIndexer::enabled()) {
            return;
        }

        app(SearchIndexer::class)->purgeType(
            $this->requireScope(null),
            $this->types->forModel($model),
        );
    }

    /**
     * @param  string  $name
     * @param  array<string, mixed>  $options
     */
    public function createIndex($name, array $options = []): never
    {
        throw new LogicException(
            'The postgres engine has no named indexes. `search_documents` is one table and scope '
            .'separation is a column predicate, not a per-scope index. Nothing needs creating.'
        );
    }

    /**
     * @param  string  $name
     */
    public function deleteIndex($name): never
    {
        throw new LogicException(
            'The postgres engine has no named indexes. To empty one scope\'s corpus use '
            .'SearchIndexer::purgeScope(), or `scout-postgres:reindex --prune` to remove orphans only.'
        );
    }

    /**
     * Run one search and return the hits.
     *
     * @param  Builder<covariant Model>  $builder
     */
    private function execute(Builder $builder, int $limit): SearchResults
    {
        if (! PostgresSearchService::available()) {
            return SearchResults::empty();
        }

        [$service, $type] = $this->context($builder);

        return $service->search(SearchQuery::make(
            term: (string) $builder->query,
            locale: $this->localeFor($builder, $type),
            types: [$type],
            limit: $limit,
            filters: $this->filtersFor($builder),
            anyFilters: $this->anyFiltersFor($builder),
            rangeFilters: $this->rangeFiltersFor($builder),
        ));
    }

    /**
     * The hydration builder behind both paginators.
     *
     * @param  Builder<covariant Model>  $builder
     * @return EloquentBuilder<covariant Model>
     */
    private function hydrationQuery(Builder $builder): EloquentBuilder
    {
        [$service, $type] = $this->context($builder);

        $results = PostgresSearchService::available()
            ? $this->execute($builder, min(self::RESULT_CAP, $this->limitFor($builder, self::RESULT_CAP)))
            : SearchResults::empty();

        $query = $service->hydrateQuery($results, $type);

        if ($builder->queryCallback !== null) {
            ($builder->queryCallback)($query);
        }

        $this->applyOrders($builder, $query);

        return $query;
    }

    /**
     * The scope-pinned service and the corpus this builder addresses.
     *
     * @param  Builder<covariant Model>  $builder
     * @return array{0: PostgresSearchService, 1: DocumentType}
     */
    private function context(Builder $builder): array
    {
        if ($builder->index !== null) {
            throw new LogicException(
                'within() names a physical index, and this engine has none. Scope separation is a '
                .'column predicate; pass options([\'scope\' => $key]) to search a specific scope.'
            );
        }

        if ($builder->whereNotIns !== []) {
            throw new LogicException(
                'whereNotIn() is not supported: document filters compile to jsonb containment, '
                .'which has no negative form. Filter after hydration with query(), or add a '
                .'positive filter key at index time.'
            );
        }

        return [
            PostgresSearchService::for($this->requireScope($builder)),
            $this->types->forModel($builder->model),
        ];
    }

    /**
     * Resolve the scope, or refuse.
     *
     * Order: the explicit builder option, then the bound ScopeResolver. Never a
     * fallback to "every scope", and never a quiet empty result — see the class
     * docblock.
     *
     * Returns null only when the corpus is genuinely unscoped, which is a
     * configured state rather than a failure to resolve one.
     *
     * @param  Builder<covariant Model>  $builder
     */
    private function requireScope(?Builder $builder): ?int
    {
        if (! $this->scope->isScoped()) {
            return null;
        }

        $explicit = $builder?->options['scope'] ?? null;

        if (is_int($explicit)) {
            return $explicit;
        }

        if (is_string($explicit) && $explicit !== '') {
            return (int) $explicit;
        }

        if ($explicit !== null && $this->resolver !== null) {
            // A tenant model, or whatever else the adopter's resolver understands.
            return $this->resolver->normalize($explicit);
        }

        if ($this->resolver === null) {
            throw UnresolvableScope::noAmbientScope();
        }

        return $this->resolver->current();
    }

    /**
     * The scope a model belongs to, read from its own scope column.
     *
     * Returns null when the corpus is unscoped, or when the model carries no
     * usable value — the caller decides what to do about the latter.
     */
    private function scopeOf(Model $model): ?int
    {
        if (! $this->scope->isScoped()) {
            return null;
        }

        $value = $model->getAttribute($this->scope->requireColumn());

        return is_numeric($value) && (int) $value !== 0 ? (int) $value : null;
    }

    /**
     * A locale is mandatory — the corpus holds one row per (model, locale), so an
     * unconstrained query returns the same model once per locale.
     * Non-translatable types are stored under a single sentinel locale.
     *
     * @param  Builder<covariant Model>  $builder
     */
    private function localeFor(Builder $builder, DocumentType $type): string
    {
        if (! $type->isTranslatable()) {
            return DocumentType::LOCALE_ANY;
        }

        $explicit = $builder->options['locale'] ?? null;

        return is_string($explicit) && $explicit !== '' ? $explicit : app()->getLocale();
    }

    /**
     * Scout `where()` with `=` → jsonb containment on the `filters` column.
     *
     * `Builder::wheres` is a **list** of `['field', 'operator', 'value']` triples,
     * not the `field => value` map `SearchQuery` takes. Returning it verbatim
     * produced a containment test against the keys `0`, `1`, … — which match no
     * document, so every `where()` narrowed the result set to nothing and reported
     * no error. Found by a Scout/service parity test, not by a type check: both
     * shapes are `array`.
     *
     * @param  Builder<covariant Model>  $builder
     * @return array<string, mixed>
     */
    private function filtersFor(Builder $builder): array
    {
        $filters = [];

        foreach ($builder->wheres as $where) {
            if ($this->operatorOf($where) === '=') {
                $filters[(string) $where['field']] = $where['value'];
            }
        }

        return $filters;
    }

    /**
     * Scout `where()` with a comparison operator → a numeric bound.
     *
     * Only the inclusive operators are accepted. `filters->>key` is compared as
     * `numeric`, and the bounds it understands are `min` (`>=`) and `max` (`<=`);
     * a strict `>` or `<` has no representation there, and quietly widening it to
     * the inclusive form would admit exactly the boundary row the caller excluded.
     *
     * @param  Builder<covariant Model>  $builder
     * @return array<string, array{min?: scalar, max?: scalar}>
     */
    private function rangeFiltersFor(Builder $builder): array
    {
        $ranges = [];

        foreach ($builder->wheres as $where) {
            $operator = $this->operatorOf($where);

            if ($operator === '=') {
                continue;
            }

            $bound = match ($operator) {
                '>=' => 'min',
                '<=' => 'max',
                default => throw new LogicException(
                    "where() operator [{$operator}] is not supported: document bounds compile to a "
                    .'numeric comparison on the jsonb filters column, which has only an inclusive '
                    .'min and max. Use >= or <=, or filter after hydration with query().'
                ),
            };

            $field = (string) $where['field'];
            $ranges[$field] ??= [];
            $ranges[$field][$bound] = $where['value'];
        }

        return $ranges;
    }

    /**
     * @param  array{field: string, operator?: string, value: mixed}  $where
     */
    private function operatorOf(array $where): string
    {
        return (string) ($where['operator'] ?? '=');
    }

    /**
     * Scout `whereIn()` → ORed containments, each still index-served.
     *
     * @param  Builder<covariant Model>  $builder
     * @return array<string, list<scalar>>
     */
    private function anyFiltersFor(Builder $builder): array
    {
        $filters = [];

        foreach ($builder->whereIns as $field => $values) {
            $filters[$field] = array_values($values);
        }

        return $filters;
    }

    /**
     * @param  Builder<covariant Model>  $builder
     */
    private function limitFor(Builder $builder, ?int $default = null): int
    {
        return $builder->limit ?? $default ?? self::DEFAULT_LIMIT;
    }

    /**
     * A caller's orderBy() applies to the hydrated models, replacing relevance
     * order. Applied here rather than ignored: the columns are real columns on the
     * model's own table, and silently dropping an explicit ordering is the kind of
     * quiet wrongness this stack keeps paying for.
     *
     * @param  Builder<covariant Model>  $builder
     * @param  EloquentBuilder<covariant Model>  $query
     */
    private function applyOrders(Builder $builder, EloquentBuilder $query): void
    {
        foreach ($builder->orders as $order) {
            $query->orderBy($order['column'], $order['direction']);
        }
    }
}
