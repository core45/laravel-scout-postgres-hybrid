<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Search;

use Core45\ScoutPostgres\Contracts\DocumentType;
use Core45\ScoutPostgres\Contracts\EmbeddingProvider;
use Core45\ScoutPostgres\DTOs\SearchQuery;
use Core45\ScoutPostgres\DTOs\SearchResults;
use Core45\ScoutPostgres\Exceptions\UnresolvableScope;
use Core45\ScoutPostgres\Scope\ScopeDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * The only way to read `search_documents`.
 *
 * Where a scope column is configured, every tenant shares this one table and that
 * column is the whole of the isolation. That is worth stating plainly because it
 * is a downgrade from physical per-tenant indexes, where the separation itself was
 * the defence. A global scope does not fill the gap: a scope that throws with no
 * bound tenant is *inert* on exactly the paths that matter — queue workers, console
 * commands and every unauthenticated path are where reindexing and RAG run. So
 * every branch below states the scope predicate itself, and package code does not
 * query `SearchDocument` outside this namespace (D11).
 *
 * Four constraints shape the SQL and none of them announce themselves when
 * violated — each one silently returns fewer rows, or none:
 *
 * - **The `regconfig` is bound as a constant, one per branch (C9).** A tsquery
 *   built from the `text_search_config` *column* is not a constant, so the GIN
 *   index is unreachable — verified as `Seq Scan … Disabled: true` under
 *   `enable_seqscan = off`. Because `LOCALE_ANY` rows are indexed with the
 *   `default` configuration and locale rows with their own, that means the
 *   keyword side is two branches, not one.
 * - **Every branch also carries `text_search_config = ?`.** Redundant for index
 *   mechanics; it exists to fail *closed* if the locale map changed and the
 *   reindex has not run yet (C9).
 * - **Trigram matching is `word_similarity`, written `trigram_text %> ?` with
 *   the column on the LEFT (C2).** The inverted form `trigram_text <% ?`
 *   returns zero rows and looks like "no results", not an error.
 * - **`SET LOCAL` is a no-op outside a transaction (C10).** The threshold and
 *   the query it governs run inside one `DB::transaction()`, which is why a
 *   read-only search opens a transaction at all.
 */
final class PostgresSearchService
{
    /**
     * Candidate multiplier for fusion branches.
     *
     * Reciprocal rank fusion can only reorder what each branch returned, so a
     * branch limited to the caller's final `limit` cannot contribute a document
     * that ranks 15th lexically and 1st semantically. Over-fetching each branch
     * is what makes the fusion worth doing.
     */
    private const int CANDIDATE_MULTIPLIER = 4;

    private function __construct(
        private readonly ?int $scopeKey,
        private readonly ScopeDefinition $scope,
        private readonly QueryExpander $expander,
    ) {}

    /**
     * A reader pinned to one scope.
     *
     * The scope is a constructor argument rather than something read from
     * ambient context precisely because the ambient context is absent or
     * disabled on the paths that matter (D11).
     *
     * SC-1: null means *the corpus is unscoped*. If the corpus is in fact
     * partitioned and null arrives, this throws rather than constructing a
     * service whose branches would emit no scope predicate at all. An unfiltered
     * query and an empty result set both read as "no matches" to the caller,
     * while the first is a cross-scope leak.
     */
    public static function for(?int $scope): self
    {
        /** @var ScopeDefinition $definition */
        $definition = app(ScopeDefinition::class);

        if ($definition->isScoped() && $scope === null) {
            throw UnresolvableScope::noAmbientScope();
        }

        return new self(
            // Normalised to null when unscoped, so `scopeKey() === null` is exactly
            // "no predicate is emitted" rather than an id nothing pins to.
            $definition->isScoped() ? $scope : null,
            $definition,
            app(QueryExpander::class),
        );
    }

    /**
     * Whether a search can run at all.
     *
     * `search_documents`, `f_unaccent()` and every operator below are
     * PostgreSQL-only, and an adopter may well run the rest of the application —
     * or its test suite — on another driver (C4, D4). Callers get an empty result
     * set rather than an exception, matching the indexer's own `enabled()`.
     */
    public static function available(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * Hybrid search: keyword, trigram and semantic candidates fused by RRF.
     *
     * The default entry point. Each branch is ranked independently and scored
     * `weight / (k + rank)`, so a document found by two branches outranks one
     * found by either alone without any of the three scales — `ts_rank_cd`,
     * `word_similarity`, cosine similarity — having to be comparable (D2).
     */
    public function search(SearchQuery $query): SearchResults
    {
        return $this->hybrid($query);
    }

    /**
     * Full-text search alone.
     */
    public function keyword(SearchQuery $query): SearchResults
    {
        return $this->runSingle($query, $this->keywordBranch($query, $query->limit));
    }

    /**
     * Trigram similarity alone — the typo and inflection tolerance PostgreSQL
     * full-text search has none of, and the only thing a language with no
     * stemmer has at all (C1, C2).
     */
    public function trigram(SearchQuery $query): SearchResults
    {
        return $this->runSingle($query, $this->trigramBranch($query, $query->limit));
    }

    /**
     * Vector search alone.
     *
     * Delegates to `SemanticSearchService`, which uses the framework's vector
     * helpers rather than raw SQL — the helpers express a pure semantic lookup
     * exactly; what they cannot express is the window ranking fusion needs
     * (D2).
     */
    public function semantic(SearchQuery $query): SearchResults
    {
        return app(SemanticSearchService::class)->search($this->scopeKey, $query);
    }

    /**
     * @param  list<float>|null  $vector  pre-computed query embedding; omitted means
     *                                    "embed the term now", null result means the
     *                                    semantic branch is skipped
     */
    public function hybrid(SearchQuery $query, ?array $vector = null): SearchResults
    {
        if (! self::available()) {
            return SearchResults::empty();
        }

        $width = $query->limit * self::CANDIDATE_MULTIPLIER;

        $vector ??= app(SemanticSearchService::class)->embed($this->scopeKey, $query->term);

        $branches = array_values(array_filter([
            $this->keywordBranch($query, $width),
            $this->trigramBranch($query, $width),
            $this->semanticBranch($query, $width, $vector),
        ]));

        if ($branches === []) {
            return SearchResults::empty();
        }

        return $this->runFused($query, $branches);
    }

    /**
     * Source models for one type, in hit order, pinned to this scope.
     *
     * Order is re-applied in PHP rather than with `array_position()` in SQL so
     * that callers adding their own scopes through `hydrateQuery()` get the
     * same guarantee without having to preserve an ordering expression.
     *
     * @return EloquentCollection<int, Model>
     */
    public function hydrate(SearchResults $results, DocumentType $type): EloquentCollection
    {
        $ids = $results->idsFor($type);

        if ($ids === []) {
            /** @var EloquentCollection<int, Model> $empty */
            $empty = new EloquentCollection;

            return $empty;
        }

        $models = $this->hydrateQuery($results, $type)->get()->keyBy(
            fn (Model $model): int => (int) $model->getKey(),
        );

        /** @var EloquentCollection<int, Model> $ordered */
        $ordered = new EloquentCollection(array_values(array_filter(array_map(
            fn (int $id): ?Model => $models->get($id),
            $ids,
        ))));

        return $ordered;
    }

    /**
     * A builder for the matched source models of one type, in relevance order.
     *
     * Scope-pinned explicitly (C3): hydrating through an ambient-context `find()`
     * is precisely the pattern D11 exists to remove, so when the corpus is
     * partitioned the scope predicate is restated here on the *source* model's
     * table. That is a documented adopter requirement — source models must carry
     * the scope column — not an optimisation.
     *
     * Which of the host's global scopes to strip comes from
     * `config('scout-postgres.hydration.strip_global_scopes')` and defaults to
     * empty. The package cannot guess an adopter's scope names, and stripping one
     * it was not told about would be the package widening a query on its own
     * initiative. A visibility scope answers a different question — who may *see*
     * this — so a caller that cares should re-apply its own visibility scopes on
     * top of the builder returned here.
     *
     * The relevance order is applied in SQL rather than left to the caller,
     * because the callers that need a builder rather than a collection are
     * exactly the ones that `paginate()` — and a paginator discards any order
     * reimposed in PHP afterwards. `array_position` over the id list is the
     * only ordering that survives it.
     *
     * **Relevance is the primary sort and a caller's own `orderBy()` is a
     * tie-breaker, not a replacement.** It is added to the builder returned
     * here, so it lands *after* the `array_position` term and only separates
     * documents the fusion scored identically. A caller wanting its own sort to
     * lead must call `reorder()` on this builder first.
     *
     * That is a decision, not an oversight. Making a caller's ordering primary
     * from inside this method is reachable — deferring the `array_position`
     * term to a `beforeQuery()` callback would append it at execution time,
     * after anything the caller added. It is rejected because
     * `applyBeforeQueryCallbacks()` also fires on `getCountForPagination()`,
     * which clones the builder without orders and consumes the callback list:
     * the page query would then silently lose relevance ordering altogether.
     * Trading a documented tie-breaker for a framework-internals-dependent
     * failure that no assertion here would see is the worse deal.
     *
     * C4 pins v0.1 to integer keys, so the cast is `?::bigint[]` rather than
     * something derived from the model's key type.
     *
     * @return Builder<covariant Model>
     */
    public function hydrateQuery(SearchResults $results, DocumentType $type): Builder
    {
        $class = $type->modelClass();
        $ids = $results->idsFor($type);

        /** @var Builder<covariant Model> $builder */
        $builder = $class::query();

        foreach ($this->strippedGlobalScopes() as $globalScope) {
            $builder->withoutGlobalScope($globalScope);
        }

        if ($this->scope->isScoped()) {
            $builder->where($this->scope->requireColumn(), $this->scopeKey);
        }

        $builder->whereKey($ids);

        if ($ids !== []) {
            $table = $builder->getModel()->getTable();
            $key = $builder->getModel()->getKeyName();

            $builder->orderByRaw(
                'array_position(?::bigint[], '.$table.'.'.$key.')',
                ['{'.implode(',', $ids).'}'],
            );
        }

        return $builder;
    }

    /**
     * The global scopes the adopter has asked to be stripped while hydrating.
     *
     * @return list<string>
     */
    private function strippedGlobalScopes(): array
    {
        /** @var list<string> $scopes */
        $scopes = config('scout-postgres.hydration.strip_global_scopes', []);

        return $scopes;
    }

    /**
     * Run one branch on its own, scoring by that branch's native rank.
     */
    private function runSingle(SearchQuery $query, ?SearchBranch $branch): SearchResults
    {
        if (! self::available() || ! $branch instanceof SearchBranch) {
            return SearchResults::empty();
        }

        $sql = <<<SQL
            SELECT searchable_type,
                   searchable_id,
                   locale,
                   title,
                   rank AS score,
                   '{$branch->name}' AS sources
              FROM ({$branch->sql}) AS {$branch->name}
             ORDER BY score DESC, searchable_id
             LIMIT ?
            SQL;

        return $this->execute($query, $sql, [...$branch->bindings, $query->limit]);
    }

    /**
     * Fuse branches with reciprocal rank fusion.
     *
     * `weight / (k + rank)`, summed per document. `k` damps low-ranked hits;
     * the keyword weight covers the trigram branch too, because both are
     * lexical evidence and `config/scout-postgres.php` deliberately exposes one
     * lexical and one semantic dial rather than three.
     *
     * The float casts are not decoration: `? / (? + rn)` with untyped
     * parameters resolves against `rn`'s bigint and performs integer division,
     * making every score zero.
     *
     * @param  list<SearchBranch>  $branches
     */
    private function runFused(SearchQuery $query, array $branches): SearchResults
    {
        /** @var float $keywordWeight */
        $keywordWeight = config('scout-postgres.hybrid.weights.keyword', 1.0);
        /** @var float $semanticWeight */
        $semanticWeight = config('scout-postgres.hybrid.weights.semantic', 1.0);
        // Clamped: `k` is a denominator, and a misconfigured 0 or negative
        // value divides by zero at rank 1 — a 500 on the search page rather
        // than a bad ranking.
        $k = max(1, (int) config('scout-postgres.hybrid.k', 60));

        $ctes = [];
        $scored = [];
        $bindings = [];
        $scoreBindings = [];

        foreach ($branches as $branch) {
            $ctes[] = "{$branch->name} AS (
                SELECT *, ROW_NUMBER() OVER (ORDER BY rank DESC, searchable_id) AS rn
                  FROM ({$branch->sql}) AS {$branch->name}_raw
            )";

            $scored[] = "SELECT searchable_type, searchable_id, locale, title,
                                (?::float8 / (?::int + rn)) AS score,
                                '{$branch->name}' AS source
                           FROM {$branch->name}";

            array_push($bindings, ...$branch->bindings);
            array_push(
                $scoreBindings,
                // A branch *name*, not a document type: this is the one string
                // comparison in this file that is not a DocumentType value().
                $branch->name === 'semantic' ? $semanticWeight : $keywordWeight,
                $k,
            );
        }

        $sql = 'WITH '.implode(",\n", $ctes).",\n".
            'scored AS ('.implode("\n UNION ALL \n", $scored).')
             SELECT searchable_type,
                    searchable_id,
                    locale,
                    MIN(title) AS title,
                    SUM(score) AS score,
                    string_agg(DISTINCT source, \',\' ORDER BY source) AS sources
               FROM scored
              GROUP BY searchable_type, searchable_id, locale
              ORDER BY score DESC, searchable_id
              LIMIT ?';

        return $this->execute($query, $sql, [...$bindings, ...$scoreBindings, $query->limit]);
    }

    /**
     * Run the assembled statement with the trigram threshold in force.
     *
     * The `set_config(..., is_local: true)` call and the query must share one
     * transaction or the threshold silently reverts to PostgreSQL's 0.6 default
     * and the trigram branch returns fewer rows than configured — no error, no
     * warning (C10). `set_config()` rather than `SET LOCAL` because the latter
     * takes no parameters and would mean interpolating a float into SQL.
     *
     * `$query->types` is handed to `SearchResults::fromRows()` because
     * `DocumentType` is an interface with no enum-style `from()`: a row's
     * `searchable_type` is resolved against the candidate set rather than
     * constructed from the stored string. That set is always sufficient — every
     * branch constrains `searchable_type` to values drawn from `$query->types`,
     * so no row can come back carrying a type the caller did not ask for.
     *
     * @param  list<mixed>  $bindings
     */
    private function execute(SearchQuery $query, string $sql, array $bindings): SearchResults
    {
        /** @var float $threshold */
        $threshold = config('scout-postgres.trigram.word_similarity_threshold', 0.4);

        /** @var list<array<string, mixed>> $rows */
        $rows = DB::transaction(function () use ($sql, $bindings, $threshold): array {
            DB::select('SELECT set_config(?, ?, true)', [
                'pg_trgm.word_similarity_threshold',
                (string) $threshold,
            ]);

            return array_map(
                static fn (object $row): array => (array) $row,
                DB::select($sql, $bindings),
            );
        });

        return SearchResults::fromRows($rows, $query->types);
    }

    /**
     * Full-text candidates.
     *
     * Two sub-branches, because the bound `regconfig` has to be a constant and
     * `LOCALE_ANY` rows were indexed with a different one (C9). They are
     * combined with `UNION ALL` and ranked together; `ts_rank_cd` values from
     * two configurations are not strictly comparable, but they are the same
     * *kind* of quantity, and the fused path replaces them with ranks anyway.
     */
    private function keywordBranch(SearchQuery $query, int $limit): ?SearchBranch
    {
        if (! $query->hasTerm()) {
            return null;
        }

        $variants = $this->expander->expand($query->term);

        if ($variants === []) {
            return null;
        }

        $parts = [];
        $bindings = [];

        $groups = [
            [$query->translatableTypes(), $query->locale],
            // Was SearchableType::LOCALE_ANY; the constant now lives on the
            // DocumentType contract.
            [$query->localeAnyTypes(), DocumentType::LOCALE_ANY],
        ];

        foreach ($groups as [$types, $locale]) {
            if ($types === []) {
                continue;
            }

            $config = TextSearchConfig::for($locale);
            $tsquery = $this->tsqueryExpression(count($variants));

            $typePlaceholders = implode(', ', array_fill(0, count($types), '?'));
            // Was `$type->value` on the host enum. DocumentType is an interface,
            // so the stored discriminator is read through `value()`.
            $typeValues = array_map(fn (DocumentType $type): string => $type->value(), $types);

            [$scopeSql, $scopeBindings] = $this->scopePredicate();
            [$filterSql, $filterBindings] = $this->filterPredicate($query);

            $parts[] = "(
                SELECT searchable_type, searchable_id, locale, title,
                       ts_rank_cd(search_vector, {$tsquery}) AS rank
                  FROM search_documents
                 WHERE {$scopeSql}locale = ?
                   AND text_search_config = ?
                   AND searchable_type IN ({$typePlaceholders}){$filterSql}
                   AND search_vector @@ {$tsquery}
                 ORDER BY rank DESC, searchable_id
                 LIMIT ?
            )";

            array_push(
                $bindings,
                ...$this->tsqueryBindings($config, $variants),
                ...$scopeBindings,
                ...[$locale, $config],
                ...$typeValues,
                ...$filterBindings,
                ...$this->tsqueryBindings($config, $variants),
                ...[$limit],
            );
        }

        if ($parts === []) {
            return null;
        }

        return new SearchBranch('keyword', implode(' UNION ALL ', $parts), $bindings);
    }

    /**
     * Trigram candidates.
     *
     * One branch: no `regconfig` is involved, so the two locale groups can
     * share a statement. `f_unaccent(lower(?))` mirrors the expression the
     * indexer writes with — a PHP-side transliteration would not match
     * `unaccent.rules` and would compare normalised text against raw input,
     * matching nothing (C3).
     */
    private function trigramBranch(SearchQuery $query, int $limit): ?SearchBranch
    {
        if (! $query->hasTerm()) {
            return null;
        }

        $variants = $this->expander->expand($query->term);

        if ($variants === []) {
            return null;
        }

        [$localeSql, $localeBindings] = $this->localePredicate($query);

        if ($localeSql === null) {
            return null;
        }

        [$scopeSql, $scopeBindings] = $this->scopePredicate();
        [$filterSql, $filterBindings] = $this->filterPredicate($query);

        $rankParts = [];
        $matchParts = [];

        for ($i = 0, $count = count($variants); $i < $count; $i++) {
            $rankParts[] = 'word_similarity(f_unaccent(lower(?)), trigram_text)';
            // Column on the LEFT. `trigram_text <% ?` inverts the operands and
            // returns zero rows without erroring (C2).
            $matchParts[] = 'trigram_text %> f_unaccent(lower(?))';
        }

        $rank = count($rankParts) === 1
            ? $rankParts[0]
            : 'GREATEST('.implode(', ', $rankParts).')';

        $sql = "
            SELECT searchable_type, searchable_id, locale, title,
                   {$rank} AS rank
              FROM search_documents
             WHERE {$scopeSql}{$localeSql}{$filterSql}
               AND (".implode(' OR ', $matchParts).')
             ORDER BY rank DESC, searchable_id
             LIMIT ?
        ';

        $bindings = [
            ...$variants,
            ...$scopeBindings,
            ...$localeBindings,
            ...$filterBindings,
            ...$variants,
            $limit,
        ];

        return new SearchBranch('trigram', $sql, $bindings);
    }

    /**
     * Vector candidates for the fused query.
     *
     * Raw rather than the framework helper because fusion needs this branch to
     * be one CTE among several, ranked by a window function (D2). Exact
     * distance, no ANN index: pgvector post-filters an ANN candidate set, so a
     * query narrowed to one scope and one type can come back short — or empty —
     * while matches exist. That is a correctness property, not a speed one
     * (D1).
     *
     * `embedding_fingerprint = ?` is as load-bearing as `embedding IS NOT NULL`
     * and excludes rows for the same reason. `EmbeddingProvider::fingerprint()`
     * identifies provider *and* model, and vectors from two different models are
     * not comparable — a distance between them is a plausible-looking number
     * that means nothing. Without this predicate, deploying model v2 with the
     * same dimension count ranks v2 query vectors against every not-yet-backfilled
     * v1 document vector, and the ranking is meaningless with no error anywhere.
     * `= ?` rather than `IS NOT DISTINCT FROM`, so a row carrying a vector but no
     * fingerprint fails closed.
     *
     * The provider is resolved from the container here rather than taken in the
     * constructor, the same way `semantic()` resolves `SemanticSearchService`:
     * this branch is reachable via `hybrid($query, $vector)` with a caller-supplied
     * vector, which never touches the provider otherwise, and the binding is
     * unconditional (`bindIf` to `NullEmbeddingProvider`).
     *
     * @param  list<float>|null  $vector
     */
    private function semanticBranch(SearchQuery $query, int $limit, ?array $vector): ?SearchBranch
    {
        // An empty array is "no vector", not a zero-dimensional one. Encoded and
        // bound it becomes `'[]'::vector`, which PostgreSQL rejects outright
        // with *"vector must have at least 1 dimension"* — turning a caller's
        // harmless-looking `hybrid($query, [])` into a 500 on a search page.
        if ($vector === null || $vector === []) {
            return null;
        }

        [$localeSql, $localeBindings] = $this->localePredicate($query);

        if ($localeSql === null) {
            return null;
        }

        [$scopeSql, $scopeBindings] = $this->scopePredicate();
        [$filterSql, $filterBindings] = $this->filterPredicate($query);

        /** @var float $minSimilarity */
        $minSimilarity = config('scout-postgres.vector.min_similarity', 0.4);

        $encoded = json_encode($vector, JSON_THROW_ON_ERROR);

        $fingerprint = app(EmbeddingProvider::class)->fingerprint();

        $sql = "
            SELECT searchable_type, searchable_id, locale, title,
                   (1 - (embedding <=> ?::vector)) AS rank
              FROM search_documents
             WHERE {$scopeSql}{$localeSql}{$filterSql}
               AND embedding IS NOT NULL
               AND embedding_fingerprint = ?
               AND (embedding <=> ?::vector) <= ?
             ORDER BY embedding <=> ?::vector, searchable_id
             LIMIT ?
        ";

        // One binding per placeholder, in placeholder order: the SELECT vector,
        // then the WHERE predicates left to right, then ORDER BY, then LIMIT.
        // A fragment added without its binding shifts every later value by one
        // and PostgreSQL raises nothing — it simply compares the wrong pair.
        $bindings = [
            $encoded,
            ...$scopeBindings,
            ...$localeBindings,
            ...$filterBindings,
            $fingerprint,
            $encoded,
            1 - $minSimilarity,
            $encoded,
            $limit,
        ];

        return new SearchBranch('semantic', $sql, $bindings);
    }

    /**
     * The scope predicate, as the first thing after each branch's `WHERE`.
     *
     * SC-1, restated per branch rather than lifted into one outer `WHERE`. The
     * branches are separate CTEs and separate SELECTs, and the repetition is
     * defence in depth: a branch that loses its predicate loses it visibly here,
     * not silently three levels up.
     *
     * Emitted **only** when the corpus is partitioned. In `none` mode the column
     * does not exist in the table at all, so emitting it is a SQL error — and
     * emitting it by default would be the worse failure, since a column that
     * happened to exist would be filtered against a scope key of null.
     *
     * Returned as a *prefix* ending in `AND `, which is safe only because every
     * branch has at least one further predicate after it: keyword's `locale = ?`
     * is unconditional, and trigram and semantic both return null before reaching
     * here when `localePredicate()` yields nothing. Keep that true.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function scopePredicate(): array
    {
        if (! $this->scope->isScoped()) {
            return ['', []];
        }

        return [
            $this->scope->requireColumn()." = ?\n                   AND ",
            [$this->scopeKey],
        ];
    }

    /**
     * `(websearch_to_tsquery(?::regconfig, ?) || …)` for one branch.
     *
     * OR, not concatenation. Appending synonyms to the search term instead
     * would produce `'yoga' & 'joga'` — a space is AND in `websearch_to_tsquery`
     * — and make recall strictly worse than not expanding at all (C8).
     */
    private function tsqueryExpression(int $variantCount): string
    {
        return '('.implode(' || ', array_fill(0, $variantCount, 'websearch_to_tsquery(?::regconfig, ?)')).')';
    }

    /**
     * @param  list<string>  $variants
     * @return list<string>
     */
    private function tsqueryBindings(string $config, array $variants): array
    {
        $bindings = [];

        foreach ($variants as $variant) {
            array_push($bindings, $config, $variant);
        }

        return $bindings;
    }

    /**
     * The locale contract, as SQL.
     *
     * Translatable types are queried at exactly the requested locale;
     * everything else at `LOCALE_ANY`. Leaving this out returns up to one copy
     * per indexed locale of every translatable model (D10).
     *
     * @return array{0: string|null, 1: list<mixed>}
     */
    private function localePredicate(SearchQuery $query): array
    {
        $clauses = [];
        $bindings = [];

        foreach ([
            [$query->translatableTypes(), $query->locale],
            // Was SearchableType::LOCALE_ANY; the constant now lives on the
            // DocumentType contract.
            [$query->localeAnyTypes(), DocumentType::LOCALE_ANY],
        ] as [$types, $locale]) {
            if ($types === []) {
                continue;
            }

            $placeholders = implode(', ', array_fill(0, count($types), '?'));
            $clauses[] = "(locale = ? AND searchable_type IN ({$placeholders}))";

            $bindings[] = $locale;

            foreach ($types as $type) {
                // Was `$type->value` on the host enum. Two DocumentType instances
                // for the same type need not be the same object, so `value()` is
                // the only stable discriminator.
                $bindings[] = $type->value();
            }
        }

        if ($clauses === []) {
            return [null, []];
        }

        return ['('.implode(' OR ', $clauses).')', $bindings];
    }

    /**
     * Every filter shape, as one SQL fragment applied inside the search.
     *
     * All three go in the `WHERE` of each branch, never to the result set. A
     * predicate applied after `LIMIT` does not narrow a search — it deletes
     * part of the answer, and specifically the part the engine ranked highest
     * (C6).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function filterPredicate(SearchQuery $query): array
    {
        if (! $query->hasPredicates()) {
            return ['', []];
        }

        $clauses = [];
        $bindings = [];

        if ($query->filters !== []) {
            // Containment, which the GIN index on `filters` serves.
            $clauses[] = 'filters @> ?::jsonb';
            $bindings[] = json_encode($query->filters, JSON_THROW_ON_ERROR);
        }

        foreach ($query->anyFilters as $key => $values) {
            // Containment cannot express `IN`, so an accepted-values rule is a
            // disjunction of single-key containments — each one still index
            // served, unlike `filters->>'key' IN (...)`.
            $parts = [];

            foreach ($values as $value) {
                $parts[] = 'filters @> ?::jsonb';
                $bindings[] = json_encode([$key => $value], JSON_THROW_ON_ERROR);
            }

            $clauses[] = '('.implode(' OR ', $parts).')';
        }

        foreach ($query->rangeFilters as $key => $bounds) {
            // Cast rather than compared as text: `'9' > '10'` is true for
            // strings and false for numbers, and a timestamp filter compared
            // lexically silently drops everything on a digit boundary.
            //
            // Guarded, because `filters` is arbitrary JSON: one document storing
            // `price: "POA"` while the rest store numbers makes a bare
            // `(filters->>'price')::numeric` abort the entire search with
            // *"invalid input syntax for type numeric"*. A non-numeric value is
            // a row that does not match the range, not a fatal query.
            //
            // CASE rather than `regex AND cast`: PostgreSQL orders the operands
            // of an AND by estimated cost and gives no left-to-right guarantee,
            // so the cast can still be evaluated first and still throw. CASE is
            // the documented construct with a defined evaluation order.
            //
            // A regex on the text rather than `jsonb_typeof(filters->?) =
            // 'number'`, which would newly exclude a document storing `"20"` as
            // a JSON string — a value that casts fine today. The exponent group
            // is not decoration: `json_encode(1.0e+25)` writes `1.0e+25`, which
            // `::numeric` accepts, and a pattern that rejected it would turn the
            // crash into a silent missing row.
            $numeric = "(CASE WHEN filters->>? ~ '^[+-]?([0-9]+(\.[0-9]*)?|\.[0-9]+)([eE][+-]?[0-9]+)?$' THEN (filters->>?)::numeric END)";

            if (isset($bounds['min'])) {
                $clauses[] = $numeric.' >= ?';
                // The key is bound twice — once for the guard, once for the
                // cast. Dropping either shifts every later binding by one.
                array_push($bindings, $key, $key, $bounds['min']);
            }

            if (isset($bounds['max'])) {
                $clauses[] = $numeric.' <= ?';
                array_push($bindings, $key, $key, $bounds['max']);
            }
        }

        return [
            "\n                   AND ".implode("\n                   AND ", $clauses),
            $bindings,
        ];
    }

    /**
     * The scope every branch above pins itself to, or null when the corpus is
     * unscoped and no branch emits a scope predicate at all.
     */
    public function scopeKey(): ?int
    {
        return $this->scopeKey;
    }
}
