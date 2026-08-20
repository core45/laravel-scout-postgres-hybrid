<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Search;

use Core45\ScoutPostgres\Contracts\DocumentType;
use Core45\ScoutPostgres\Exceptions\UnresolvableScope;
use Core45\ScoutPostgres\Models\SearchDocument;
use Core45\ScoutPostgres\Scope\CrossScope;
use Core45\ScoutPostgres\Scope\ScopeDefinition;
use Illuminate\Database\Eloquent\Builder;

/**
 * Read-only, platform-wide counts over `search_documents`, for diagnostics.
 *
 * Exists because an operator status command needs them and the package's own
 * chokepoint discipline says nothing outside this namespace queries
 * `SearchDocument` directly — a rule an architecture test enforces, and which
 * would catch a status command reaching for the model on its own.
 *
 * **These are the one legitimately cross-scope reads of this table**, and that
 * is the whole reason this class is separate from the rest of the namespace
 * rather than folded into {@see SearchIndexer}. Every other reader states a
 * scope; an operator asking "how big is the index" is asking about the
 * platform, and a per-scope answer would not be the question. Nothing here
 * returns document *content* — only counts — so a cross-tenant total discloses
 * nothing a tenant owns.
 *
 * If a method is ever added here that returns rows rather than numbers, it
 * belongs somewhere else, behind a scope.
 *
 * Every cross-scope method below is gated by a {@see CrossScope} argument
 * rather than a boolean flag (C5/SC-3): `CrossScope` is constructed only via
 * `CrossScope::platformWide()`, so a platform-wide read is deliberate,
 * greppable, and assertable by an architecture test in a way a flag would not
 * be.
 */
final class SearchIndexStatistics
{
    /**
     * Total documents across every scope, or 0 when the table is unavailable.
     */
    public function total(CrossScope $across): int
    {
        if (! SearchIndexer::enabled()) {
            return 0;
        }

        return $this->query()->count();
    }

    /**
     * Document count per {@see DocumentType}, every given type present.
     *
     * Types with no documents are returned as 0 rather than omitted: "zero
     * products indexed" is the answer an operator most needs to see, and a
     * missing key reads as though the type does not exist.
     *
     * The source ported here iterated a host enum's `cases()`; a package
     * interface has no equivalent registry, so the caller supplies the set of
     * types it cares about instead.
     *
     * @param  list<DocumentType>  $types
     * @return array<string, int>
     */
    public function countsByType(CrossScope $across, array $types): array
    {
        $counts = [];

        foreach ($types as $type) {
            $counts[$type->value()] = 0;
        }

        if (! SearchIndexer::enabled()) {
            return $counts;
        }

        /** @var array<string, int> $found */
        $found = $this->query()
            ->selectRaw('searchable_type, count(*) as total')
            ->groupBy('searchable_type')
            ->pluck('total', 'searchable_type')
            ->all();

        foreach ($found as $type => $count) {
            $counts[$type] = (int) $count;
        }

        return $counts;
    }

    /**
     * Document count per locale, busiest first.
     *
     * @return array<string, int>
     */
    public function countsByLocale(CrossScope $across): array
    {
        if (! SearchIndexer::enabled()) {
            return [];
        }

        /** @var array<string, int> $counts */
        $counts = $this->query()
            ->selectRaw('locale, count(*) as total')
            ->groupBy('locale')
            ->orderByDesc('total')
            ->pluck('total', 'locale')
            ->all();

        return array_map(intval(...), $counts);
    }

    /**
     * Documents carrying a usable vector.
     *
     * A gap between this and {@see self::total()} is not a fault on its own —
     * an embedding is written asynchronously by a backfill job, and a row
     * whose scope has no compatible embedding model stays NULL by design,
     * degrading that document to keyword and trigram search.
     */
    public function withEmbedding(CrossScope $across): int
    {
        if (! SearchIndexer::enabled()) {
            return 0;
        }

        return $this->query()->whereNotNull('embedding')->count();
    }

    /**
     * Documents of one type belonging to one scope.
     *
     * The scoped counterpart, used for the drift comparison: it is what a
     * per-scope source count is compared against. Unlike the methods above
     * this takes no {@see CrossScope} — it reads exactly one scope, so the
     * cross-scope escape hatch does not apply.
     *
     * `?int $scope`: null means the corpus is unscoped
     * ({@see ScopeDefinition::isScoped()} false). If the definition IS scoped
     * and null arrives, this throws rather than widening to an unfiltered
     * query — SC-1.
     */
    public function countForScopeAndType(?int $scope, DocumentType $type): int
    {
        if (! SearchIndexer::enabled()) {
            return 0;
        }

        $definition = $this->scopeDefinition();

        if ($definition->isScoped() && $scope === null) {
            throw UnresolvableScope::noAmbientScope();
        }

        $query = SearchDocument::query()->where('searchable_type', $type->value());

        if ($definition->isScoped()) {
            $query->where($definition->requireColumn(), $scope);
        }

        return $query->count();
    }

    /**
     * @return Builder<SearchDocument>
     */
    private function query(): Builder
    {
        // The source ported here called `withoutGlobalScopes()` with no scope
        // predicate, "uniquely in this namespace", because a scoped query has
        // no way to express a platform-wide count. The package's own
        // SearchDocument ships with NO global scopes at all (see its
        // docblock), so there is nothing to strip here — the call is dropped
        // rather than ported silently. What still makes this platform-wide
        // read legitimate is that every public method above requires a
        // CrossScope argument (C5/SC-3).
        return SearchDocument::query();
    }

    private function scopeDefinition(): ScopeDefinition
    {
        return app(ScopeDefinition::class);
    }
}
