<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Contracts;

/**
 * Answers whether a scope still exists (C6).
 *
 * `SearchIndexer::reconcile()` guards itself with
 * `Shop::query()->withoutGlobalScopes()->whereKey($shopId)->exists()`. The scope
 * column cascades on delete, so when the tenant is gone its documents went with it
 * and there is nothing to reconcile — while proceeding would throw when the indexer
 * tried to bind the missing tenant's context.
 *
 * The package cannot query a `Shop`, and cannot assume the scope key even refers to
 * an Eloquent model. It asks this instead.
 *
 * Implementations MUST bypass the host's own tenant global scopes: reconciliation
 * runs on queues and in console commands where no tenant is bound, so a scoped
 * lookup would report every scope as missing and turn the guard into a silent
 * no-op for all reindexing.
 *
 * Not called when `ScopeDefinition::isScoped()` is false.
 */
interface ScopeRepository
{
    public function exists(int $scope): bool;
}
