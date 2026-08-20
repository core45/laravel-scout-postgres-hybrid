<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Observers;

use Core45\ScoutPostgres\Contracts\DocumentTypeRegistry;
use Core45\ScoutPostgres\Contracts\SearchIndexable;
use Core45\ScoutPostgres\Exceptions\UnknownDocumentType;
use Core45\ScoutPostgres\Jobs\SyncSearchDocumentJob;
use Core45\ScoutPostgres\Scope\ScopeDefinition;
use Core45\ScoutPostgres\Search\SearchIndexer;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;

/**
 * Keeps `search_documents` in step with the adopter's indexable models.
 *
 * Hooks `saved`, not `created`/`updated`. Translation packages commonly write
 * translations inside the parent's `saved` event, and `performUpdate()` fires
 * `updated` only when the parent itself is dirty — so a translation-only edit
 * leaves the parent clean and `updated` never fires at all.
 *
 * `ShouldHandleEventsAfterCommit` defers the handler itself until the transaction
 * commits, so a rolled-back save enqueues nothing. The queued job also sets
 * `afterCommit()`, which is belt and braces rather than redundancy — the two
 * cover different halves: whether the handler runs at all, and when the job it
 * enqueues becomes visible to a worker.
 */
class SearchDocumentObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private readonly ScopeDefinition $scope,
        private readonly DocumentTypeRegistry $types,
    ) {}

    public function saved(Model $model): void
    {
        $this->dispatchFor($model);
    }

    public function deleted(Model $model): void
    {
        // The model is gone but its attributes are still in memory, which is the
        // only place the scope value still exists — the job cannot reload a
        // deleted model to learn which scope it belonged to.
        $this->dispatchFor($model);
    }

    public function restored(Model $model): void
    {
        $this->dispatchFor($model);
    }

    private function dispatchFor(Model $model): void
    {
        if (! $model instanceof SearchIndexable) {
            return;
        }

        if (! SearchIndexer::enabled()) {
            return;
        }

        $scope = null;

        if ($this->scope->isScoped()) {
            $value = $model->getAttribute($this->scope->requireColumn());
            $scope = is_numeric($value) ? (int) $value : 0;

            if ($scope === 0) {
                // A model with no scope has no place in a scoped corpus, and a job
                // dispatched for it could only ever fail.
                return;
            }
        }

        try {
            $type = $this->types->forModel($model);
        } catch (UnknownDocumentType) {
            // The model implements SearchIndexable but the adopter has not
            // registered a type for it. Silently skipping would be the wrong
            // trade — but so would throwing inside a model save, so the indexer's
            // own reconcile path reports it instead.
            return;
        }

        dispatch(new SyncSearchDocumentJob($type->value(), (int) $model->getKey(), $scope));
    }
}
