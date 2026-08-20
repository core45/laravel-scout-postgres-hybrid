<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Concerns;

use Laravel\Scout\Searchable;

/**
 * Scout's `Searchable`, minus its model observer.
 *
 * Gives a model everything Scout's query side offers — `Model::search()`,
 * `searchable()`, `unsearchable()`, `withoutSyncingToSearch()` — while leaving
 * the automatic write path to this package's own observer.
 *
 * ## Why the observer has to go
 *
 * Scout's `ModelObserver` reacts to a searchable model's own save and delete, and
 * that is strictly less than a denormalised corpus needs. The sync job also has
 * to fire when a *contributor* changes: a renamed brand restaling every product
 * that names it, a category attached by a pivot `sync()` that emits no Eloquent
 * event at all, a translation row written directly. It re-reads the model instead
 * of carrying a serialised payload, so it cannot write a stale document, and it
 * treats save and delete as one reconcile so queue ordering stops mattering.
 *
 * Running both would double-write every save, and Scout's is the incomplete one.
 *
 * ## Why not `disableSearchSyncing()`
 *
 * Because it does not stay disabled. `Searchable::withoutSyncingToSearch()`
 * disables syncing, runs the callback, then calls `enableSearchSyncing()` in a
 * `finally` — it restores the *enabled* state rather than the previous one. So a
 * single `Model::withoutSyncingToSearch(fn () => …)` silently re-arms the
 * observer for the rest of the process.
 *
 * That is consequential because of the observer's guard order: `saved()` checks
 * `syncingDisabledFor()` first and only then calls `shouldBeSearchable()`, which
 * on a model whose relations carry a throwing tenant scope raises rather than
 * returning false.
 *
 * Not registering the observer at all has no such toggle. `bootSearchable()` is
 * overridden to do nothing; Laravel still calls it, because `bootTraits()` walks
 * `class_uses_recursive()` and so finds Scout's trait nested inside this one, but
 * it now resolves to the empty override below.
 *
 * `withoutSyncingToSearch()` therefore still exists and is simply a no-op, which
 * is the honest answer since the indexing it used to suppress is not Scout's any
 * more. Suppressing *this* package's indexing is `SearchIndexer::withoutIndexing()`.
 */
trait SearchableWithoutSyncing
{
    use Searchable {
        bootSearchable as private bootScoutSearchable;
    }

    /**
     * Deliberately empty: this is the hook that would register
     * `Laravel\Scout\ModelObserver`. See the trait docblock.
     */
    public static function bootSearchable(): void
    {
        //
    }
}
