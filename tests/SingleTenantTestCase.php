<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Tests;

/**
 * The same suite, with `scope.mode => 'none'`.
 *
 * The plan's requirement is that the whole thing passes twice, because a single
 * green configuration says nothing about an abstraction whose entire job is
 * having two. Under this base the migration emits no scope column, the lookup
 * index degrades to (searchable_type, locale), and every branch must omit its
 * scope predicate rather than filtering on a column that does not exist.
 */
abstract class SingleTenantTestCase extends TestCase
{
    protected bool $scoped = false;
}
