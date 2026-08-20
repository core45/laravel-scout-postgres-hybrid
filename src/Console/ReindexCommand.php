<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Console;

use Core45\ScoutPostgres\Contracts\DocumentType;
use Core45\ScoutPostgres\Contracts\DocumentTypeRegistry;
use Core45\ScoutPostgres\Contracts\SearchIndexable;
use Core45\ScoutPostgres\Embedding\EmbeddingBackfill;
use Core45\ScoutPostgres\Scope\ScopeDefinition;
use Core45\ScoutPostgres\Search\SearchIndexer;
use Core45\ScoutPostgres\Search\SearchReconciliation;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

/**
 * Rebuild the corpus from the source models.
 *
 * The host command this generalises took a shop argument and resolved it against
 * a `shops` table. A package cannot: it does not know what a tenant is, or how to
 * enumerate them. The scope is therefore given explicitly with `--scope`, and in
 * `none` mode it is neither needed nor accepted.
 *
 * Reindexing is idempotent — it reconciles rather than inserting — so running it
 * twice is harmless and running it after a schema or config change is the
 * supported way to restate the corpus.
 */
final class ReindexCommand extends Command
{
    protected $signature = 'scout-postgres:reindex
        {--scope=* : Scope keys to reindex. Required when scope.mode is "column".}
        {--type=* : Limit to these DocumentType values. Defaults to every registered type.}
        {--prune : Also delete documents whose source row no longer exists.}
        {--no-embeddings : Skip the embedding backfill.}
        {--embed-limit=1000 : Documents embedded per scope in one run.}
        {--chunk=200 : Source models loaded per batch.}';

    protected $description = 'Rebuild search_documents from the source models.';

    public function handle(
        SearchIndexer $indexer,
        SearchReconciliation $reconciliation,
        DocumentTypeRegistry $registry,
        ScopeDefinition $scope,
        EmbeddingBackfill $embeddings,
    ): int {
        if (! SearchIndexer::enabled()) {
            $this->error('Indexing is disabled: this engine requires a PostgreSQL connection.');

            return self::FAILURE;
        }

        $types = $this->resolveTypes($registry);

        if ($types === []) {
            return self::FAILURE;
        }

        $scopes = $this->resolveScopes($scope);

        if ($scopes === null) {
            return self::FAILURE;
        }

        foreach ($scopes as $key) {
            $this->reindexScope($indexer, $reconciliation, $types, $key, $scope);

            // After reindexing, not before: reconciling nulls the vector of every
            // document whose text changed, so embedding first would pay the
            // provider for vectors this run is about to discard.
            if (! $this->option('no-embeddings')) {
                $embedded = $embeddings->run($key, (int) $this->option('embed-limit'));
                $this->line("  embedded {$embedded} document(s)");
            }
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<DocumentType>  $types
     */
    private function reindexScope(
        SearchIndexer $indexer,
        SearchReconciliation $reconciliation,
        array $types,
        ?int $key,
        ScopeDefinition $scope,
    ): void {
        $label = $key === null ? 'the corpus' : "scope {$key}";
        $this->info("Reindexing {$label}…");

        foreach ($types as $type) {
            $this->reindexType($indexer, $type, $key, $scope);
        }

        if ($this->option('prune')) {
            $pruned = $reconciliation->pruneOrphans($key, $types);
            $this->line("  pruned {$pruned} orphaned document(s)");
        }
    }

    private function reindexType(
        SearchIndexer $indexer,
        DocumentType $type,
        ?int $key,
        ScopeDefinition $scope,
    ): void {
        $class = $type->modelClass();
        $query = $class::query();

        if ($scope->isScoped() && $key !== null) {
            $query->where($scope->requireColumn(), $key);
        }

        $count = 0;

        // chunkById, not chunk: reindexing writes to search_documents rather than
        // to the source table, but a paginated chunk() still shifts under any
        // concurrent insert or delete on the source, which silently skips rows.
        $query->chunkById((int) $this->option('chunk'), function ($models) use ($indexer, &$count): void {
            foreach ($models as $model) {
                if ($model instanceof Model && $model instanceof SearchIndexable) {
                    $indexer->reconcileModel($model);
                    $count++;
                }
            }
        });

        $this->line("  {$type->value()}: {$count} model(s)");
    }

    /**
     * @return list<DocumentType>
     */
    private function resolveTypes(DocumentTypeRegistry $registry): array
    {
        $all = $registry->all();

        if ($all === []) {
            $this->error('No document types are registered. Add them to scout-postgres.types.');

            return [];
        }

        /** @var list<string> $requested */
        $requested = (array) $this->option('type');

        if ($requested === []) {
            return $all;
        }

        $types = [];

        foreach ($requested as $value) {
            // Matched by value(), never by identity: DocumentType is an interface
            // and two instances of one type need not be the same object.
            $match = null;

            foreach ($all as $type) {
                if ($type->value() === $value) {
                    $match = $type;
                    break;
                }
            }

            if ($match === null) {
                $this->warn("Unknown document type [{$value}], skipping.");

                continue;
            }

            $types[] = $match;
        }

        if ($types === []) {
            $this->error('No valid document types were given.');
        }

        return $types;
    }

    /**
     * @return ?list<?int> null signals a usage error the caller should fail on
     */
    private function resolveScopes(ScopeDefinition $scope): ?array
    {
        /** @var list<string> $given */
        $given = (array) $this->option('scope');

        if (! $scope->isScoped()) {
            if ($given !== []) {
                $this->error('scope.mode is "none", so --scope has nothing to select. Remove it.');

                return null;
            }

            return [null];
        }

        if ($given === []) {
            // Refusing rather than reindexing every scope. The package cannot
            // enumerate an adopter's tenants, and guessing "all" from an omitted
            // option is exactly the widening SC-1 forbids.
            $this->error('scope.mode is "column": pass --scope=<key> at least once.');

            return null;
        }

        return array_map(static fn (string $key): int => (int) $key, $given);
    }
}
