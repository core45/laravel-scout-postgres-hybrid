<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Search;

use Core45\ScoutPostgres\Contracts\DocumentType;
use Core45\ScoutPostgres\Contracts\DocumentTypeRegistry;
use Core45\ScoutPostgres\Contracts\ScopeRepository;
use Core45\ScoutPostgres\Contracts\SearchIndexable;
use Core45\ScoutPostgres\DTOs\SearchDocumentData;
use Core45\ScoutPostgres\Exceptions\UnresolvableScope;
use Core45\ScoutPostgres\Models\SearchDocument;
use Core45\ScoutPostgres\Scope\ScopeDefinition;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Writes `search_documents` rows, and is the only thing that does.
 *
 * The operation this service exposes is *reconciliation*, not "index" and
 * "delete". `reconcile()` re-reads the model and makes the index match reality:
 * a model that is gone, unpublished, or out of scope produces no documents and
 * therefore has its rows removed. Save and delete being the same operation is
 * what makes queue ordering irrelevant — both orderings converge on the same
 * final state (D8).
 *
 * Two normalisation rules are enforced here rather than at the call site:
 *
 * - `text_search_config` is resolved from the locale by `TextSearchConfig`, so
 *   the trigger and the query builder read one map (D6).
 * - `trigram_text` is normalised by PostgreSQL's own `f_unaccent(lower(...))`,
 *   applied in the INSERT expression. Doing it in PHP would mean a
 *   transliteration that does not match `unaccent.rules`, and a mismatch
 *   between index-side and query-side normalisation returns zero rows without
 *   any error (C3).
 *
 * Every public entry point takes the scope key explicitly as `?int $scope`,
 * where null means "this corpus is unscoped" — the `ScopeDefinition::none()`
 * mode. SC-1: when the definition *is* scoped and null arrives, this throws
 * {@see UnresolvableScope::noAmbientScope()}. It never widens to an unfiltered
 * write or delete, because a purge that lost its scope predicate deletes every
 * tenant's rows.
 */
final class SearchIndexer
{
    /**
     * Suppression depth for `withoutIndexing()`. A counter rather than a bool so
     * nested suppression does not re-enable indexing when the inner scope ends.
     */
    private static int $suppressed = 0;

    /**
     * @param  ScopeDefinition  $scope  How the corpus is partitioned. The same object
     *                                  the migration read, so the column named here is
     *                                  the column that exists (SC-2).
     * @param  ScopeRepository|null  $scopes  Optional (C6). When null the
     *                                        scope-existence guard in
     *                                        {@see self::reconcile()} is skipped rather
     *                                        than failing: an adopter that has not bound
     *                                        one still gets a working indexer, it just
     *                                        does not get the short-circuit.
     */
    public function __construct(
        private readonly ScopeDefinition $scope,
        private readonly ?ScopeRepository $scopes = null,
        private readonly ?DocumentTypeRegistry $types = null,
    ) {}

    /**
     * Run a callback with indexing suppressed.
     *
     * Successor to Scout's `withoutSyncingToSearch()`. Two live needs: streaming
     * writers that produce placeholder rows which must never be indexed
     * mid-stream, and bulk importers which would otherwise enqueue one job per
     * model per locale plus an embedding call each. Importers wrap their run and
     * enqueue a single reindex afterwards (D14).
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public static function withoutIndexing(callable $callback): mixed
    {
        self::$suppressed++;

        try {
            return $callback();
        } finally {
            self::$suppressed--;
        }
    }

    /**
     * Whether writes are currently being recorded.
     *
     * False on any non-PostgreSQL connection: `search_documents` is only created
     * by the pgsql branch of the migration, so it does not exist there (C4). An
     * adopter whose default connection is another driver therefore gets no
     * writes rather than a crash. Callers must consult this *before* building
     * documents, not after — resolving a model's translations is the expensive
     * part.
     */
    public static function enabled(): bool
    {
        return self::$suppressed === 0 && DB::connection()->getDriverName() === 'pgsql';
    }

    /**
     * Make the index match the current state of one model.
     *
     * `$scope` is passed explicitly because a deleted model cannot be reloaded
     * to learn which scope it belonged to (D8). It also pins every write and
     * delete below to one scope, so a worker reused across tenants cannot reach
     * another tenant's rows (D11).
     */
    public function reconcile(?int $scope, DocumentType $type, int $id): void
    {
        if (! self::enabled()) {
            return;
        }

        $scope = $this->requireScope($scope);

        // C6: was `Shop::query()->withoutGlobalScopes()->whereKey($shopId)->exists()`.
        // The repository is optional, and `?->` makes a null one skip the guard
        // rather than fail. In an unscoped corpus `$scope` is null and the guard
        // does not apply at all.
        if ($scope !== null && $this->scopes?->exists($scope) === false) {
            // The scope column is typically cascadeOnDelete, so the rows went
            // with the scope. Nothing to reconcile, and resolving documents
            // below would throw on the missing tenant.
            return;
        }

        // The source wrapped this in `ShopContext::run()` so the host's tenant
        // binding was in place while translations resolved. The package has no
        // equivalent and deliberately invents none: binding ambient state for
        // `toSearchDocuments()` is the adopter's job — their ScopeResolver, their
        // context middleware, or whatever their queue job restores. What the
        // source's docblock warned about still holds for them: relations behind a
        // tenant scope that *throws* without a bound tenant will throw here, and a
        // caller that forgets to bind produces a half-built document set.
        $model = $this->resolveModel($scope, $type, $id);

        $documents = $model instanceof SearchIndexable ? $model->toSearchDocuments() : [];

        $this->syncDocuments($scope, $type, $id, $documents);
    }

    /**
     * Reconcile from an already-loaded model.
     *
     * Saves the reload when the caller has the model in hand and knows it is
     * current — a `saved` observer, or a reindex command walking a cursor.
     *
     * The type normally comes from the documents the model just produced, which
     * avoids a registry lookup on the hot path. When the model produces none
     * there is nothing to read a type from, and that is exactly the case that
     * must still delete rows — so the {@see DocumentTypeRegistry} answers instead.
     *
     * Without the registry bound this method cannot purge, and unpublishing
     * through this path would silently leave the old rows findable. That is why
     * the constructor's registry is optional in signature only: the provider
     * always binds one, and an adopter constructing this class by hand should
     * pass it.
     *
     * As in the source, the scope is read from the model itself rather than from
     * ambient state.
     */
    public function reconcileModel(Model&SearchIndexable $model): void
    {
        if (! self::enabled()) {
            return;
        }

        $scope = null;

        if ($this->scope->isScoped()) {
            // Was `$model->getAttribute('shop_id')`. The column is whatever the
            // adopter configured. The zero-guard is gated behind `isScoped()`
            // because an unscoped corpus has no such column, and `(int) null` is
            // 0 — ungated, every single-tenant call would return here having
            // indexed nothing.
            $scope = (int) $model->getAttribute($this->scope->requireColumn());

            if ($scope === 0) {
                // A model with no scope cannot be placed in a scoped index.
                return;
            }
        }

        $documents = $model->toSearchDocuments();

        if ($documents === []) {
            // An empty return means "delete every row for this model", which is
            // the contract's whole point: unpublishing and deleting converge on
            // the same state. Reaching that requires a type, and there is no
            // document left to read one from — so the registry answers instead.
            // Returning early here, as this method first did, made unpublishing a
            // silent no-op that left the document findable.
            if ($this->types !== null) {
                $this->purge($scope, $this->types->forModel($model), (int) $model->getKey());
            }

            return;
        }

        // Type from the first document. `write()` below then verifies that every
        // other document agrees with it, so a model that fabricates a mixed set
        // is still refused.
        $this->syncDocuments(
            $scope,
            $documents[0]->searchableType,
            (int) $model->getKey(),
            $documents,
        );
    }

    /**
     * Remove every document belonging to one model, in every locale.
     */
    public function purge(?int $scope, DocumentType $type, int $id): void
    {
        if (! self::enabled()) {
            return;
        }

        $this->documents($this->requireScope($scope))
            // Was `$type->value` on the host enum.
            ->where('searchable_type', $type->value())
            ->where('searchable_id', $id)
            ->delete();
    }

    /**
     * Remove every document belonging to one scope. (Was `purgeShop()`.)
     *
     * `int` rather than `?int` on purpose: deleting "the unscoped corpus" is
     * deleting the whole table, so there is no sensible `none`-mode meaning for
     * this call. `requireScope()` cannot help — it maps null to "no predicate" —
     * so this routes through `requireColumn()`, which throws
     * {@see UnresolvableScope::notScoped()} in `none` mode. This is the one
     * method where an absent scope must refuse rather than widen.
     */
    public function purgeScope(int $scope): void
    {
        if (! self::enabled()) {
            return;
        }

        $this->scope->requireColumn();

        $this->documents($scope)->delete();
    }

    /**
     * Remove every document of one type belonging to one scope.
     *
     * The middle ground between {@see self::purge()} (one model) and
     * {@see self::purgeScope()} (everything), and it exists because a caller may
     * need exactly that: purging one subsystem's footprint from a tenant with no
     * business deleting its products and posts. Routed through this class rather
     * than queried at the call site so the D11 chokepoint holds — the scope
     * predicate is stated here, once, for every reader of the table.
     */
    public function purgeType(?int $scope, DocumentType $type): int
    {
        if (! self::enabled()) {
            return 0;
        }

        return $this->documents($this->requireScope($scope))
            // Was `$type->value` on the host enum.
            ->where('searchable_type', $type->value())
            ->delete();
    }

    /**
     * Replace one model's documents with exactly this set.
     *
     * The seam `reconcile()` and `reconcileModel()` both funnel into, and the
     * entry point for callers that already hold the documents — a reindex
     * command walking a cursor, or a test asserting the write itself.
     *
     * @param  list<SearchDocumentData>  $documents
     */
    public function syncDocuments(?int $scope, DocumentType $type, int $id, array $documents): void
    {
        if (! self::enabled()) {
            return;
        }

        $this->write($this->requireScope($scope), $type, $id, $documents);
    }

    /**
     * Normalise a scope argument at the public boundary, before anything queries
     * or binds it.
     *
     * SC-1 lives here. In `none` mode the answer is null and no predicate is ever
     * applied; in `column` mode a null argument throws rather than producing a
     * write with a NULL scope column (which a `nullable => true` install would
     * happily accept) or a delete with no predicate at all.
     */
    private function requireScope(?int $scope): ?int
    {
        if (! $this->scope->isScoped()) {
            return null;
        }

        if ($scope === null) {
            throw UnresolvableScope::noAmbientScope();
        }

        return $scope;
    }

    /**
     * @param  list<SearchDocumentData>  $documents
     *
     * @throws \InvalidArgumentException when a document does not belong to the
     *                                   model being reconciled
     */
    private function write(?int $scope, DocumentType $type, int $id, array $documents): void
    {
        if ($documents === []) {
            $this->purge($scope, $type, $id);

            return;
        }

        // The (type, id) pair is passed separately because an empty set carries
        // no identity of its own — a deletion has to say what it is deleting.
        // That makes it possible for the pair and the documents to disagree,
        // and a disagreement would write rows under one identity while the
        // reconciliation delete below scopes itself to another, leaving orphans
        // that answer searches forever. Refuse it.
        $locales = [];

        foreach ($documents as $document) {
            // Compared by `value()` rather than by identity. The host compared
            // two enum cases with `!==`, which is sound for a backed enum; two
            // `DocumentType` instances describing the same type need not be the
            // same object, so identity here would reject legitimate documents.
            if ($document->searchableType->value() !== $type->value() || $document->searchableId !== $id) {
                throw new \InvalidArgumentException(sprintf(
                    'SearchIndexer: document %s does not belong to %s:%d.',
                    $document->key(),
                    // Was `$type->value`.
                    $type->value(),
                    $id,
                ));
            }

            // Two documents for one locale collide on the unique key *inside*
            // one INSERT, which PostgreSQL rejects with "ON CONFLICT DO UPDATE
            // command cannot affect row a second time". Refusing here names the
            // model that produced the duplicate; letting it reach the database
            // names only the statement.
            if (in_array($document->locale, $locales, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'SearchIndexer: %s:%d produced two documents for locale [%s].',
                    // Was `$type->value`.
                    $type->value(),
                    $id,
                    $document->locale,
                ));
            }

            $locales[] = $document->locale;
        }

        DB::transaction(function () use ($scope, $type, $id, $documents): void {
            $this->upsert($scope, $documents);

            // Reconciliation delete: a locale that used to produce a document
            // and no longer does (the scope dropped it from its available
            // locales, or the model stopped rendering there) must lose its row.
            // Without this the upsert alone would leave an orphan that still
            // answers searches (D10).
            $this->documents($scope)
                // Was `$type->value`.
                ->where('searchable_type', $type->value())
                ->where('searchable_id', $id)
                ->whereNotIn('locale', array_map(
                    fn (SearchDocumentData $document): string => $document->locale,
                    $documents,
                ))
                ->delete();
        });
    }

    /**
     * @param  list<SearchDocumentData>  $documents
     */
    private function upsert(?int $scope, array $documents): void
    {
        // The scope column appears in the column list, in every VALUES tuple and
        // in the SET clause, or in none of the three. `$scope` is already
        // normalised by `requireScope()`, so null here means the table genuinely
        // has no such column — writing a placeholder for it would be a column
        // count mismatch, and writing one binding too many would shift every
        // subsequent value by one position, comparing a scope id against a locale
        // without PostgreSQL complaining.
        $scoped = $scope !== null;

        // Safe to interpolate: `ScopeDefinition` validates the column against an
        // identifier pattern when it is constructed.
        $column = $scoped ? $this->scope->requireColumn() : null;

        // 10 placeholders when scoped, 9 when not; 12 and 11 columns respectively,
        // the difference being the two `now()` literals.
        $tuple = $scoped
            ? '(?, ?, ?, ?, ?, ?, ?, f_unaccent(lower(?)), ?, ?::jsonb, now(), now())'
            : '(?, ?, ?, ?, ?, ?, f_unaccent(lower(?)), ?, ?::jsonb, now(), now())';

        $rows = [];
        $bindings = [];

        foreach ($documents as $document) {
            // f_unaccent(lower(?)) is the one and only normaliser. The query
            // side must build its trigram operand with the identical
            // expression, or `%>` compares normalised text against raw input
            // and silently matches nothing (C2, C3).
            $rows[] = $tuple;

            // Bindings are pushed in exact statement order. The scope binding is
            // first when present, matching its position in the column list.
            if ($scoped) {
                $bindings[] = $scope;
            }

            array_push(
                $bindings,
                // Was `$document->searchableType->value`.
                $document->searchableType->value(),
                $document->searchableId,
                $document->locale,
                TextSearchConfig::for($document->locale),
                $document->title,
                $document->body,
                $document->trigramSource,
                $document->contentHash(),
                json_encode($document->filters === [] ? new \stdClass : $document->filters, JSON_THROW_ON_ERROR),
            );
        }

        $scopeColumn = $scoped ? $column.', ' : '';
        $scopeAssignment = $scoped ? $column.' = EXCLUDED.'.$column.",\n                " : '';

        DB::statement(
            'INSERT INTO search_documents
                ('.$scopeColumn.'searchable_type, searchable_id, locale, text_search_config,
                 title, body, trigram_text, content_hash, filters, created_at, updated_at)
             VALUES '.implode(', ', $rows).'
             ON CONFLICT (searchable_type, searchable_id, locale) DO UPDATE SET
                '.$scopeAssignment.'text_search_config = EXCLUDED.text_search_config,
                title              = EXCLUDED.title,
                body               = EXCLUDED.body,
                trigram_text       = EXCLUDED.trigram_text,
                content_hash       = EXCLUDED.content_hash,
                filters            = EXCLUDED.filters,
                updated_at         = now(),
                -- Text changed means the stored vector describes text that is no
                -- longer there. Dropping it degrades this document to keyword +
                -- trigram until the backfill re-embeds it, which is honest;
                -- keeping it would answer semantic queries from deleted content.
                -- Unchanged text keeps its vector, so a reindex costs no
                -- embedding calls at all (D10).
                embedding = CASE
                    WHEN search_documents.content_hash IS DISTINCT FROM EXCLUDED.content_hash
                    THEN NULL ELSE search_documents.embedding END,
                embedding_fingerprint = CASE
                    WHEN search_documents.content_hash IS DISTINCT FROM EXCLUDED.content_hash
                    THEN NULL ELSE search_documents.embedding_fingerprint END',
            $bindings,
        );
    }

    /**
     * A builder pinned to one scope.
     *
     * The conflict target of the upsert deliberately excludes the scope column
     * (D2), so this predicate — not the unique index — is what keeps one tenant's
     * reconciliation delete off another's rows (D11). It is applied explicitly
     * rather than left to a global scope: the package model ships none at all
     * (see `SearchDocument`'s class docblock), which is why the source's
     * `withoutGlobalScopes()` call has no counterpart here — there is nothing to
     * remove, not a call that was quietly dropped.
     *
     * A null `$scope` is only ever produced by `requireScope()` in `none` mode,
     * where the column does not exist and an unfiltered builder is the whole
     * corpus by definition.
     *
     * @return Builder<SearchDocument>
     */
    private function documents(?int $scope): Builder
    {
        $query = SearchDocument::query();

        if ($scope !== null) {
            $query->where($this->scope->requireColumn(), $scope);
        }

        return $query;
    }

    /**
     * Load the source model, pinned to the scope being reconciled.
     *
     * Explicitly pinned rather than left to the host's own tenant global scope,
     * for two reasons that pull in the same direction. Such a scope is *inert* on
     * the paths this runs on — a queue worker is unauthenticated, and platform
     * console commands typically disable it — so relying on it would filter
     * nothing (D11). And where it is not inert it fails *closed*, throwing
     * without an ambient tenant, which would make correctness depend on every
     * caller remembering to bind context first. C3: this is why source models
     * must carry the scope column.
     *
     * The scopes stripped are the adopter's, named in
     * `scout-postgres.hydration.strip_global_scopes` (C2) — the host application's
     * hardcoded `'shop'` and `'shopAccess'` are its values to configure, not the
     * package's to guess. Stripped one by one rather than with
     * `withoutGlobalScopes()`, because soft-delete scopes are deliberately left
     * on: a soft-deleted model is gone as far as search is concerned, and a null
     * return here means "remove the rows", which is exactly right.
     */
    private function resolveModel(?int $scope, DocumentType $type, int $id): ?Model
    {
        $class = $type->modelClass();

        $query = $class::query();

        /** @var list<string> $strip */
        $strip = config('scout-postgres.hydration.strip_global_scopes', []);

        foreach ($strip as $name) {
            $query->withoutGlobalScope($name);
        }

        if ($scope !== null) {
            $query->where($this->scope->requireColumn(), $scope);
        }

        return $query->find($id);
    }
}
