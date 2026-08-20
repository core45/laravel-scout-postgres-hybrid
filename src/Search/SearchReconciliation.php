<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Search;

use Core45\ScoutPostgres\Contracts\DocumentType;
use Core45\ScoutPostgres\Exceptions\UnresolvableScope;
use Core45\ScoutPostgres\Models\SearchDocument;
use Core45\ScoutPostgres\Scope\ScopeDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Finds and removes `search_documents` rows whose source model no longer
 * exists — orphans that no per-model reconciliation can ever see.
 *
 * A per-document sync job (D8 in the source this was ported from) only ever
 * reconciles the one `(scope, type, id)` it was dispatched for. A dropped
 * job, an exhausted retry count, or a bulk `Model::query()->delete()` that
 * fires no Eloquent events all strand a document with nothing left to
 * reconcile it — a reindex command walks *surviving* models and structurally
 * cannot see a row whose model is gone. This class is the missing
 * counterpart: it walks `search_documents` and asks "does the source still
 * exist", the direction the write path never looks.
 *
 * **Orphan means the source row is absent, nothing more.** A row that exists
 * but no longer *qualifies* for indexing is *stale*, not orphaned; that is
 * what a reindex repairs by re-reading the model and rewriting or purging its
 * documents. This class never re-reads a model's content, only whether the
 * row is there.
 *
 * The existence check has to agree with the indexer's own model resolution,
 * which deliberately leaves every scope on the source model in force except
 * the tenancy scopes named for stripping — a soft-deleted source row means
 * "remove the documents". A raw query against the bare table would see a
 * soft-deleted row as present and miss exactly the orphans that matter, so
 * every "does it exist" check here is built through the model's own Eloquent
 * query builder, with only the configured global scopes stripped
 * (identically to the indexer's own resolution). That way any scope the
 * model carries — a soft-deleting scope included — is still applied, without
 * this class needing to know which models use it.
 *
 * Which global scopes to strip on the source model comes from
 * `config('scout-postgres.hydration.strip_global_scopes')`, a list defaulting
 * to empty — the package never guesses a host's scope names. The scope
 * column itself comes from `ScopeDefinition::requireColumn()`, applied only
 * when `ScopeDefinition::isScoped()` is true; in an unscoped install neither
 * query filters on a scope at all.
 *
 * One more consequence worth stating because it looks like a bug otherwise:
 * a document whose `searchable_id` exists but under a *different* scope
 * counts as an orphan for this scope, because every existence check is
 * scope-pinned — exactly what the indexer's own resolution would return null
 * for. That is the drift `search_documents`' unique key (excluding the scope
 * column) was designed to surface, not a bug in this class.
 */
final class SearchReconciliation
{
    /**
     * Per {@see DocumentType} value, how many documents reference a source
     * row that no longer exists (or is soft-deleted) for this scope.
     *
     * `?int $scope`: null means the corpus is unscoped
     * ({@see ScopeDefinition::isScoped()} false). If the definition IS scoped
     * and null arrives, this throws rather than widening to an unfiltered
     * query — SC-1.
     *
     * The source ported here iterated a host enum's `cases()`; a package
     * interface has no equivalent registry, so the caller supplies the set of
     * types it cares about instead.
     *
     * @param  list<DocumentType>  $types
     * @return array<string, int>
     */
    public function orphanCounts(?int $scope, array $types): array
    {
        if (! SearchIndexer::enabled()) {
            return [];
        }

        $counts = [];

        foreach ($types as $type) {
            $counts[$type->value()] = $this->orphanQuery($scope, $type)->count();
        }

        return $counts;
    }

    /**
     * Delete every orphaned document for one scope, across all given types.
     *
     * `?int $scope` carries the same SC-1 contract as {@see self::orphanCounts()}:
     * null is only valid when the corpus is unscoped, and this never falls
     * back to an unfiltered DELETE — a lost scope predicate here would delete
     * other tenants' rows, not just read them.
     *
     * @param  list<DocumentType>  $types
     * @return int number of documents deleted
     */
    public function pruneOrphans(?int $scope, array $types): int
    {
        if (! SearchIndexer::enabled()) {
            return 0;
        }

        $deleted = 0;

        foreach ($types as $type) {
            $deleted += (int) $this->orphanQuery($scope, $type)->delete();
        }

        return $deleted;
    }

    /**
     * `search_documents` rows for one (scope, type) whose `searchable_id` is
     * not among the type's currently-existing rows for that scope.
     *
     * The subquery is built through the model's own Eloquent query builder,
     * not `DB::table()`, so it goes through `Builder::toBase()` →
     * `applyScopes()` and picks up every global scope the model still has
     * registered, minus the ones named for stripping — see class docblock.
     * Nothing is ever loaded into PHP: the subquery is passed straight to
     * `whereNotIn()`, which accepts an Eloquent builder and inlines its SQL
     * as a bound subquery.
     *
     * Selecting the primary key sidesteps the classic "`NOT IN` against a set
     * containing NULL matches nothing" trap — an auto-incrementing id is
     * never NULL, so that failure mode cannot occur here. If a model with a
     * nullable custom key ever became searchable, this would need
     * `whereNotExists()` instead, which has no such hazard.
     *
     * @return Builder<SearchDocument>
     */
    private function orphanQuery(?int $scope, DocumentType $type): Builder
    {
        $definition = $this->scopeDefinition();

        if ($definition->isScoped() && $scope === null) {
            throw UnresolvableScope::noAmbientScope();
        }

        $class = $type->modelClass();

        /** @var Builder<Model> $sourceQuery */
        $sourceQuery = $class::query();

        /** @var list<string> $globalScopesToStrip */
        $globalScopesToStrip = config('scout-postgres.hydration.strip_global_scopes', []);

        foreach ($globalScopesToStrip as $globalScope) {
            $sourceQuery->withoutGlobalScope($globalScope);
        }

        if ($definition->isScoped()) {
            $sourceQuery->where($definition->requireColumn(), $scope);
        }

        $existingIds = $sourceQuery->select((new $class)->getKeyName());

        // The source ported here called `withoutGlobalScopes()` on the
        // document query too. The package's own SearchDocument ships with NO
        // global scopes at all (see its docblock), so there is nothing to
        // strip — the call is dropped rather than ported silently.
        $documentQuery = SearchDocument::query()
            ->where('searchable_type', $type->value())
            ->whereNotIn('searchable_id', $existingIds);

        if ($definition->isScoped()) {
            $documentQuery->where($definition->requireColumn(), $scope);
        }

        return $documentQuery;
    }

    private function scopeDefinition(): ScopeDefinition
    {
        return app(ScopeDefinition::class);
    }
}
