<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Search;

use Core45\ScoutPostgres\Contracts\DocumentType;
use Core45\ScoutPostgres\Contracts\EmbeddingProvider;
use Core45\ScoutPostgres\DTOs\SearchHit;
use Core45\ScoutPostgres\DTOs\SearchQuery;
use Core45\ScoutPostgres\DTOs\SearchResults;
use Core45\ScoutPostgres\Exceptions\UnresolvableScope;
use Core45\ScoutPostgres\Models\SearchDocument;
use Core45\ScoutPostgres\Scope\ScopeDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Pure vector lookups over `search_documents`.
 *
 * Uses the framework's vector helpers rather than raw SQL. The earlier claim
 * that `whereVectorSimilarTo()` forces an ordering was wrong — its signature is
 * `($column, $vector, $minSimilarity = 0.6, $order = true)` — so a pure
 * semantic lookup is expressible with the helper and only the *fused* query
 * needs raw SQL, for window ranks the helper cannot produce (D2).
 *
 * Two defaults are deliberately overridden every time:
 *
 * - `minSimilarity` defaults to **0.6** in the helper, which is far stricter
 *   than this package wants. It is always passed explicitly from
 *   `config('scout-postgres.vector.min_similarity')`.
 * - No ANN index exists on `embedding`, so this is an exact scan. That is the
 *   correctness choice from D1: pgvector post-filters ANN candidates, so a
 *   query narrowed to one scope and one type can return fewer rows than asked
 *   for, or none, while matches exist.
 */
final class SemanticSearchService
{
    public function __construct(
        private readonly EmbeddingProvider $embeddings,
        private readonly ScopeDefinition $scope,
    ) {}

    /**
     * Cache value standing for "this scope could not embed anything just now".
     *
     * A distinct sentinel rather than `null`, because the cache cannot
     * distinguish a stored null from a miss and the whole point of storing it
     * is to *stop* retrying.
     */
    private const string UNAVAILABLE = 'unavailable';

    /**
     * Embed a query string for one scope.
     *
     * Returns null — never throws — when the term is empty, vector search is
     * switched off, or the provider cannot produce a usable vector (including
     * the wrong dimension count, which the `EmbeddingProvider` implementation
     * is expected to turn into null rather than an exception). A null vector
     * means "run without the semantic branch", which degrades search to
     * keyword + trigram rather than failing it (D3).
     *
     * Both halves of the cache exist because this call sits on the request
     * path with nothing to queue it behind. Measured against a live provider:
     * **~210ms per search**, paid whether the call succeeds or fails. The
     * failure case is the worse one — a rate-limited or unreachable provider
     * charges the full round-trip and then contributes no branch at all, on
     * every single query.
     *
     * @return list<float>|null
     *
     * @throws UnresolvableScope when the corpus is scoped and no scope key was given (SC-1)
     */
    public function embed(?int $scope, string $text): ?array
    {
        $text = trim($text);

        if ($text === '') {
            return null;
        }

        // SC-1: fail closed. A scoped corpus with no bound scope must never
        // fall through to an unfiltered — i.e. cross-tenant — vector lookup.
        if ($this->scope->isScoped() && $scope === null) {
            throw UnresolvableScope::noAmbientScope();
        }

        /** @var bool $enabled */
        $enabled = config('scout-postgres.vector.enabled', true);

        if (! $enabled) {
            return null;
        }

        /** @var string $prefix */
        $prefix = config('scout-postgres.cache.prefix');

        // The fingerprint is part of both keys, so a scope that switches
        // embedding model stops serving vectors from the old space
        // immediately — the read-side counterpart of `embedding_fingerprint`
        // (D3).
        $fingerprint = $this->embeddings->fingerprint();

        // C7: the scope segment comes from cacheSegment(), which is '' in
        // `none` mode so a single-tenant install gets no dangling separator.
        // $scope is guaranteed non-null here whenever the corpus is scoped
        // (guarded above), so the `?? 0` only ever feeds the unscoped branch,
        // where cacheSegment() ignores its argument entirely.
        $scopeSegment = $this->scope->cacheSegment($scope ?? 0);
        $cacheKey = $prefix.':vector:query:'.$scopeSegment.$fingerprint.':'.hash('sha256', $text);

        /** @var list<float>|null $cached */
        $cached = Cache::get($cacheKey);

        // Checked *before* the outage sentinel: a term whose vector is already
        // cached needs no provider at all, so an outage has no reason to take
        // its semantic branch away.
        if (is_array($cached)) {
            return $cached;
        }

        $outageKey = $prefix.':vector:down:'.$scopeSegment.$fingerprint;

        if (Cache::get($outageKey) === self::UNAVAILABLE) {
            return null;
        }

        // embed() rather than an exception-throwing variant: a search request
        // must not 500 because an embedding provider is down. A backfill job
        // that needs provider failures to escape is a different call path.
        $vector = $this->embeddings->embed($text);

        if ($vector === null) {
            Cache::put($outageKey, self::UNAVAILABLE, (int) config('scout-postgres.vector.unavailable_ttl', 60));

            return null;
        }

        Cache::put($cacheKey, $vector, (int) config('scout-postgres.vector.query_cache_ttl', 3600));

        return $vector;
    }

    /**
     * Nearest documents to the query term.
     *
     * @param  list<float>|null  $vector  pre-computed embedding; omitted means embed the term now
     *
     * @throws UnresolvableScope when the corpus is scoped and no scope key was given (SC-1)
     */
    public function search(?int $scope, SearchQuery $query, ?array $vector = null): SearchResults
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return SearchResults::empty();
        }

        // SC-1: guarded here too, and not left to embed()/documents() alone,
        // since a caller passing a pre-computed $vector would otherwise skip
        // embed()'s guard and reach documents() with no scope predicate.
        if ($this->scope->isScoped() && $scope === null) {
            throw UnresolvableScope::noAmbientScope();
        }

        $vector ??= $this->embed($scope, $query->term);

        if ($vector === null) {
            return SearchResults::empty();
        }

        /** @var float $minSimilarity */
        $minSimilarity = config('scout-postgres.vector.min_similarity', 0.4);

        $documents = $this->documents($scope, $query)
            ->select(['searchable_type', 'searchable_id', 'locale', 'title'])
            ->selectVectorDistance('embedding', $vector, 'vector_distance')
            ->whereNotNull('embedding')
            // As load-bearing as whereNotNull() above, and excluding rows for the
            // same reason. fingerprint() identifies provider *and* model, and
            // vectors from two different models are not comparable — a distance
            // between them is a plausible-looking number that means nothing.
            // Without this, deploying model v2 with the same dimension count
            // ranks v2 query vectors against every not-yet-backfilled v1 document
            // vector, silently. An equality comparison rather than a
            // "distinct from" one, so a row carrying a vector but no fingerprint
            // fails closed.
            ->where('embedding_fingerprint', $this->embeddings->fingerprint())
            // Ordering is wanted here: this method's whole product is "nearest
            // first". `order: false` belongs to the fused path, which ranks the
            // branch itself and does not come through this method.
            ->whereVectorSimilarTo('embedding', $vector, $minSimilarity, true)
            ->limit($query->limit)
            ->get();

        $hits = [];

        foreach ($documents as $document) {
            $title = $document->getAttribute('title');

            // SearchDocument casts `searchable_type` to a plain string, not a
            // DocumentType (see the model's class docblock) — the package has
            // no enum to cast to. SearchHit::fromRow() resolves the stored
            // string back to one of the query's candidate DocumentType
            // instances by value(), which is the same resolution the host
            // application previously got for free from an Eloquent enum cast.
            $hits[] = SearchHit::fromRow([
                'searchable_type' => $document->getAttribute('searchable_type'),
                'searchable_id' => $document->getAttribute('searchable_id'),
                'locale' => $document->getAttribute('locale'),
                'score' => 1.0 - (float) $document->getAttribute('vector_distance'),
                'title' => $title,
                'sources' => 'semantic',
            ], $query->types);
        }

        return new SearchResults($hits);
    }

    /**
     * How many documents this scope currently has a usable vector for.
     *
     * Exists so full recall under filtering (D1) can be asserted against a
     * real number rather than an assumption.
     *
     * @throws UnresolvableScope when the corpus is scoped and no scope key was given (SC-1)
     */
    public function embeddedCount(?int $scope): int
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return 0;
        }

        if ($this->scope->isScoped() && $scope === null) {
            throw UnresolvableScope::noAmbientScope();
        }

        // No withoutGlobalScopes() call: the package's SearchDocument ships no
        // global scopes at all (see its class docblock), unlike the host
        // application's BelongsToShop — there is nothing here to strip.
        $builder = SearchDocument::query()->whereNotNull('embedding');

        if ($this->scope->isScoped()) {
            $builder->where($this->scope->requireColumn(), $scope);
        }

        return $builder->count();
    }

    /**
     * A builder pinned to one scope and to the locale contract.
     *
     * The scope predicate is applied explicitly here, only when the corpus is
     * scoped, rather than trusted to a model-level global scope — the package's
     * SearchDocument carries none, since a scope that throws with no bound
     * value is inert on exactly the paths that matter — queue workers and
     * console commands are unauthenticated — so relying on one would be
     * relying on a guard that is absent when it counts (D11).
     *
     * @return Builder<SearchDocument>
     */
    private function documents(?int $scope, SearchQuery $query): Builder
    {
        $builder = SearchDocument::query();

        if ($this->scope->isScoped()) {
            $builder->where($this->scope->requireColumn(), $scope);
        }

        $builder->where(function (Builder $inner) use ($query): void {
            $applied = false;

            foreach ([
                [$query->translatableTypes(), $query->locale],
                [$query->localeAnyTypes(), DocumentType::LOCALE_ANY],
            ] as [$types, $locale]) {
                if ($types === []) {
                    continue;
                }

                $applied = true;

                $inner->orWhere(function (Builder $group) use ($types, $locale): void {
                    // DocumentType is an interface, not an enum: two instances
                    // representing the same value() need not be the same
                    // object, so the comparison is on value() rather than on
                    // identity.
                    $group->where('locale', $locale)->whereIn(
                        'searchable_type',
                        array_map(fn (DocumentType $type): string => $type->value(), $types),
                    );
                });
            }

            if (! $applied) {
                // Unreachable through SearchQuery::make(), which refuses an
                // empty type list — but a predicate that silently matched
                // everything would be a cross-type leak, so fail closed.
                $inner->whereRaw('1 = 0');
            }
        });

        if ($query->filters !== []) {
            $builder->whereRaw('filters @> ?::jsonb', [json_encode($query->filters, JSON_THROW_ON_ERROR)]);
        }

        foreach ($query->anyFilters as $key => $values) {
            $builder->where(function (Builder $group) use ($key, $values): void {
                foreach ($values as $value) {
                    $group->orWhereRaw('filters @> ?::jsonb', [json_encode([$key => $value], JSON_THROW_ON_ERROR)]);
                }
            });
        }

        foreach ($query->rangeFilters as $key => $bounds) {
            // Guarded exactly as `PostgresSearchService::filterPredicate()`
            // guards it, and for the same reason: `filters` is arbitrary JSON,
            // so one document storing `price: "POA"` among numbers would abort
            // the whole search with *"invalid input syntax for type numeric"*.
            // A non-numeric value is a row outside the range, not a fatal query.
            // CASE rather than `regex AND cast` because PostgreSQL orders the
            // operands of an AND by cost and may still evaluate the cast first.
            // The key is bound twice — once for the guard, once for the cast.
            $numeric = "(CASE WHEN filters->>? ~ '^[+-]?([0-9]+(\.[0-9]*)?|\.[0-9]+)([eE][+-]?[0-9]+)?$' THEN (filters->>?)::numeric END)";

            if (isset($bounds['min'])) {
                $builder->whereRaw($numeric.' >= ?', [$key, $key, $bounds['min']]);
            }

            if (isset($bounds['max'])) {
                $builder->whereRaw($numeric.' <= ?', [$key, $key, $bounds['max']]);
            }
        }

        return $builder;
    }
}
