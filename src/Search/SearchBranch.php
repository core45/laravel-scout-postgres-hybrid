<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Search;

/**
 * One candidate-producing SELECT, with its bindings in statement order.
 *
 * Raw SQL assembled from fragments is the one place where a binding can drift
 * out of position without any error — PostgreSQL happily compares a scope id
 * against a locale. Keeping each fragment and its own bindings in the same
 * object means the two can only be appended together, so the order is a
 * property of the construction rather than of remembering to keep two arrays
 * in step.
 */
final readonly class SearchBranch
{
    /**
     * @param  list<mixed>  $bindings
     */
    public function __construct(
        public string $name,
        public string $sql,
        public array $bindings,
    ) {}
}
