<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Jobs;

use Core45\ScoutPostgres\Contracts\DocumentTypeRegistry;
use Core45\ScoutPostgres\Exceptions\UnknownDocumentType;
use Core45\ScoutPostgres\Search\SearchIndexer;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Make `search_documents` agree with one model's current state.
 *
 * One job for saves *and* deletes, deliberately. The obvious alternative, an
 * index job plus a delete job, is not safe here for two reasons:
 *
 * - Separate classes never share a unique lock. `UniqueLock::getKey()` keys on
 *   `get_class($job)`, so two classes with the same `uniqueId()` produce two
 *   independent locks, and a save can run concurrently with a delete for the same
 *   model, in either order.
 * - Collapsing them into one class under plain `ShouldBeUnique` makes the lock
 *   swallow the delete: the lock is held until *after* the body runs, so a delete
 *   dispatched mid-flight is discarded and the row survives.
 *
 * The resolution is to carry no payload and re-read the model. "Make the index
 * match reality" is idempotent, so ordering stops mattering — both orderings of
 * save and delete converge on the same final state.
 *
 * `ShouldBeUniqueUntilProcessing` releases the lock *before* the body runs. Plain
 * `ShouldBeUnique` would swallow a change arriving while the job is executing:
 * the job has already read the pre-change state, and the replacement dispatch
 * would never be queued. That is staleness let in through the front door.
 */
class SyncSearchDocumentJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30, 60];

    public int $timeout = 120;

    public int $uniqueFor = 600;

    /**
     * @param  string  $searchableType  The `DocumentType` value. A scalar rather than
     *                                  the type object so the payload survives both
     *                                  serialisation and a type being removed
     *                                  mid-flight.
     * @param  ?int  $scope  null when the corpus is unscoped.
     */
    public function __construct(
        public string $searchableType,
        public int $searchableId,
        public ?int $scope,
    ) {
        $this->onQueue((string) config('scout-postgres.queue', 'default'));

        // Every dispatch is post-commit, set here rather than left to each caller.
        // A translation package that writes rows inside the parent's `saved` event
        // would otherwise race the very rows this job exists to index.
        $this->afterCommit();
    }

    /**
     * Keyed by model, **not** by locale.
     *
     * One job owns every locale row of one model, so the per-locale fan-out cannot
     * interleave with itself and leave a half-updated document set. Scope-prefixed,
     * so two tenants can never contend for one lock.
     */
    public function uniqueId(): string
    {
        return 'scope:'.($this->scope ?? 'none').":search-sync:{$this->searchableType}:{$this->searchableId}";
    }

    /**
     * @return array<int, string>
     */
    public function tags(): array
    {
        return ['scope:'.($this->scope ?? 'none')];
    }

    public function handle(SearchIndexer $indexer, DocumentTypeRegistry $types): void
    {
        try {
            $type = $types->fromValue($this->searchableType);
        } catch (UnknownDocumentType) {
            // A type removed from the registry after this job was queued. Nothing
            // sensible to reconcile, and retrying cannot help.
            Log::warning('SyncSearchDocumentJob: unknown document type, discarding.', [
                'searchable_type' => $this->searchableType,
                'searchable_id' => $this->searchableId,
                'scope' => $this->scope,
            ]);

            return;
        }

        $indexer->reconcile($this->scope, $type, $this->searchableId);
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Search document sync failed', [
            'searchable_type' => $this->searchableType,
            'searchable_id' => $this->searchableId,
            'scope' => $this->scope,
            'error' => $exception->getMessage(),
        ]);
    }
}
