<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Exceptions;

use InvalidArgumentException;

/**
 * The `scout-postgres.scope` config array cannot be hydrated into a coherent
 * ScopeDefinition.
 *
 * This is the SC-2 failure mode. A typo'd key must fail loudly at boot rather
 * than degrade into "search everything" at query time, so every constructor of
 * this exception describes what was wrong with the input rather than falling
 * back to a default.
 */
final class InvalidScopeConfiguration extends InvalidArgumentException implements ScoutPostgresException
{
    public static function unknownMode(mixed $mode): self
    {
        return new self(sprintf(
            'scout-postgres.scope.mode must be "column" or "none", got %s. It is never inferred: a '
            .'mistyped mode would silently drop the tenant filter from every query.',
            self::describe($mode),
        ));
    }

    public static function missingColumn(): self
    {
        return new self(
            'scout-postgres.scope.mode is "column" but scope.column is empty. Name the column that '
            .'partitions the corpus, or set mode to "none" for a single-tenant install.',
        );
    }

    public static function missingForeignTable(): self
    {
        return new self(
            'scout-postgres.scope.foreign_key is true but scope.table is empty. Name the table the '
            .'scope column references, or set foreign_key to false.',
        );
    }

    /**
     * The identifier pattern is case-**sensitive** and stated here in one place.
     *
     * It was `/…/i` in 1.0.0, which accepted `Tenant_ID`. That could never work:
     * the schema builder quotes identifiers while the engine's raw SQL does not,
     * so the migration created `"Tenant_ID"` and every query looked for the
     * folded `tenant_id` and failed with "column does not exist". Uppercase is now
     * rejected at boot, where the message can say what to use instead.
     */
    public static function invalidIdentifier(string $key, mixed $value): self
    {
        return new self(sprintf(
            'scout-postgres.scope.%s must be a lowercase unquoted PostgreSQL identifier matching '
            .'/^[a-z_][a-z0-9_$]*$/, got %s. Write it in snake_case — "tenant_id", not "Tenant_ID". '
            .'The value is interpolated into DDL and into raw SQL rather than escaped, and PostgreSQL '
            .'lowercases an unquoted name, so anything with an uppercase letter names one column in '
            .'the migration and a different one in every query.',
            $key,
            self::describe($value),
        ));
    }

    public static function reservedIdentifier(string $key, string $value): self
    {
        return new self(sprintf(
            'scout-postgres.scope.%s is string(%s), a reserved PostgreSQL keyword. Choose a name '
            .'that does not collide with the SQL grammar — "%s_id" or "owner_%s" rather than "%s". '
            .'The value is interpolated unquoted into the search and upsert statements, where a '
            .'keyword is a syntax error rather than a column.',
            $key,
            $value,
            $value,
            $value,
            $value,
        ));
    }

    public static function invalidOnDelete(mixed $action): self
    {
        return new self(sprintf(
            'scout-postgres.scope.on_delete must be one of "cascade", "restrict", "set null", '
            .'"no action", got %s.',
            self::describe($action),
        ));
    }

    public static function setNullRequiresNullableColumn(): self
    {
        return new self(
            'scout-postgres.scope.on_delete is "set null" but scope.nullable is false. A nullable '
            .'scope column is required for that referential action.',
        );
    }

    public static function invalidType(string $key, string $expected, mixed $value): self
    {
        return new self(sprintf(
            'scout-postgres.scope.%s must be %s, got %s.',
            $key,
            $expected,
            self::describe($value),
        ));
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_scalar($value) => sprintf('%s(%s)', gettype($value), (string) $value),
            default => gettype($value),
        };
    }
}
