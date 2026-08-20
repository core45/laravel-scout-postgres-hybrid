<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Exceptions;

use RuntimeException;

/**
 * A scope value was required and could not be produced.
 *
 * SC-1: where a scope column is configured, this throws. It never widens to an
 * unfiltered query and never returns an empty result set to paper over the
 * missing scope — both would read as "no matches" to a caller that is in fact
 * being served every tenant's rows, or none of its own.
 *
 * This mirrors `PostgresDocumentEngine::requireShop()` in the host application,
 * which already fails closed for the same reason.
 */
final class UnresolvableScope extends RuntimeException implements ScoutPostgresException
{
    public static function noAmbientScope(): self
    {
        return new self(
            'No scope is currently resolvable. The package fails closed rather than querying every '
            .'scope: bind a scope before searching or indexing, or configure scout-postgres.scope.mode '
            .'as "none" if this install is single-tenant.',
        );
    }

    public static function cannotNormalize(mixed $scope): self
    {
        return new self(sprintf(
            'Cannot derive a scope key from %s.',
            is_object($scope) ? $scope::class : gettype($scope),
        ));
    }

    /**
     * The scope column was not loaded on a model the package was asked to index,
     * delete or reconcile.
     *
     * Distinct from a model whose scope is genuinely empty: that one is simply not
     * indexable and is skipped. This is a model whose scope is unknowable, usually
     * because it was loaded with a partial `select()`. Skipping it silently means a
     * delete that never happens, and a document that stays searchable after its
     * source row is gone.
     */
    public static function attributeMissing(string $model, string $column): self
    {
        return new self(sprintf(
            'The scope column [%s] was not loaded on [%s], so its scope cannot be determined. '
            .'Load the column (a partial select() is the usual cause), or call the explicitly '
            .'scoped API. Refusing rather than guessing: skipping would silently leave the '
            .'document in the index.',
            $column,
            $model,
        ));
    }

    public static function notScoped(): self
    {
        return new self(
            'scout-postgres.scope.mode is "none", so there is no scope value to resolve. Guard the '
            .'call with ScopeDefinition::isScoped().',
        );
    }
}
