<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Scope;

/**
 * The named cross-scope API (C5).
 *
 * Platform-wide reads are a real requirement, not a leak: `SearchIndexStatistics`
 * in the host application calls `withoutGlobalScopes()` with no scope predicate on
 * purpose, "uniquely in this namespace", because a count across every tenant cannot
 * be expressed through a scoped query at all.
 *
 * SC-3 requires that such reads exist *only* through a named path. This is that path,
 * and it is a distinct type rather than a boolean flag on an existing method for two
 * reasons. A flag is reachable by accident — `true` in the wrong argument position
 * silently widens a query — whereas constructing this class is deliberate and greppable.
 * And a type is what an architecture test can assert on: the P2/P6 rule is that no
 * package code calls `withoutGlobalScopes()` outside a method whose signature names
 * `CrossScope`.
 *
 * The `$reason` is mandatory and appears in no query. It exists so that every
 * platform-wide read carries, at the call site, a written justification a reviewer
 * can weigh — the same discipline the host's `query()` docblock applies by hand.
 */
final readonly class CrossScope
{
    /**
     * @param  non-empty-string  $reason
     */
    private function __construct(public string $reason) {}

    /**
     * @param  non-empty-string  $reason  why this read must span every scope
     */
    public static function platformWide(string $reason): self
    {
        return new self($reason);
    }
}
