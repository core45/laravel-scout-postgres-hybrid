<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\DTOs;

use Core45\ScoutPostgres\Contracts\DocumentType;
use Illuminate\Support\Collection;

/**
 * An ordered result set.
 *
 * Order is the product — every consumer either renders it directly or hydrates
 * models and re-applies it — so this class keeps the hit order authoritative
 * and exposes `idsFor()` for the hydration step rather than doing the hydration
 * itself. Loading source models is tenant-pinned work and belongs to the
 * service that knows the scope (D11).
 *
 * @implements \IteratorAggregate<int, SearchHit>
 */
final readonly class SearchResults implements \Countable, \IteratorAggregate
{
    /**
     * @param  list<SearchHit>  $hits
     */
    public function __construct(public array $hits = []) {}

    public static function empty(): self
    {
        return new self;
    }

    /**
     * @param  iterable<int, array<string, mixed>>  $rows
     * @param  list<DocumentType>  $types  candidate types each row's searchable_type is matched
     *                                     against — see SearchHit::fromRow(); DocumentType has no
     *                                     enum-style from(), so the caller must supply the set.
     */
    public static function fromRows(iterable $rows, array $types): self
    {
        $hits = [];

        foreach ($rows as $row) {
            $hits[] = SearchHit::fromRow($row, $types);
        }

        return new self($hits);
    }

    public function count(): int
    {
        return count($this->hits);
    }

    public function isEmpty(): bool
    {
        return $this->hits === [];
    }

    /**
     * @return \ArrayIterator<int, SearchHit>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator($this->hits);
    }

    /**
     * Matched source ids for one type, in result order, deduplicated.
     *
     * Deduplication matters: a hybrid query can return the same model twice
     * when two locale rows both match, and the caller wants models, not rows.
     *
     * @return list<int>
     */
    public function idsFor(DocumentType $type): array
    {
        $ids = [];

        foreach ($this->hits as $hit) {
            // DocumentType instances are compared by value(), not identity: the
            // interface gives no guarantee that the $type passed in is the same
            // object (or even class) as the one attached to a hit.
            if ($hit->searchableType->value() === $type->value() && ! in_array($hit->searchableId, $ids, true)) {
                $ids[] = $hit->searchableId;
            }
        }

        return $ids;
    }

    /**
     * Every matched type, in first-appearance order.
     *
     * @return list<DocumentType>
     */
    public function types(): array
    {
        $types = [];
        $seenValues = [];

        foreach ($this->hits as $hit) {
            // Track seen types by value() rather than in_array(..., $types, true):
            // identity/equality comparison on DocumentType instances is not reliable
            // across implementations, only value() is the stable discriminator.
            if (! in_array($hit->searchableType->value(), $seenValues, true)) {
                $seenValues[] = $hit->searchableType->value();
                $types[] = $hit->searchableType;
            }
        }

        return $types;
    }

    /**
     * @return Collection<int, SearchHit>
     */
    public function collect(): Collection
    {
        return new Collection($this->hits);
    }
}
