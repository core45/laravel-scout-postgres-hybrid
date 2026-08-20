<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\DTOs;

use Core45\ScoutPostgres\Contracts\DocumentType;

/**
 * One search request, fully resolved before any SQL is built.
 *
 * The locale is **not** optional and has no ambient default. `search_documents`
 * holds one row per (model, locale), so a query with no locale predicate
 * returns up to six copies of the same model — and the caller is the only thing
 * that knows which locale the user is actually looking at (D10).
 *
 * Three filter shapes, all applied **inside** the search query rather than to
 * its results — the invariant C6 is written about. A predicate applied after
 * `LIMIT` does not narrow the search, it discards part of the answer, and the
 * rows it discards were the ones the engine ranked highest.
 *
 * - `$filters` — jsonb containment (`filters @> ?`), which the GIN index on
 *   `filters` serves. `filters->>'key' = ?` would be equally correct and
 *   entirely unindexed, so containment is the only equality form expressed here.
 * - `$anyFilters` — key ⇒ accepted values, ORed containments. Needed because
 *   containment cannot express `IN`: an audience rule like "a customer sees
 *   `customer` and `public`" is two containments, not one.
 * - `$rangeFilters` — numeric bounds on a filter key, as
 *   `(filters->>'key')::numeric`. Not index-served, and deliberately so: a
 *   freshness bound belongs in the `WHERE` even unindexed, because the
 *   alternative is truncating first and filtering the survivors.
 *
 * **`$types` has no default, on purpose.** The obvious default — indexing every
 * document type the adopter has registered — silently includes
 * `ConversationMessage`, whose documents carry raw customer support transcripts
 * in `body`. A storefront controller writing the natural-looking
 * `SearchQuery::make($term, $locale)` would then return other customers'
 * conversations, and the hit itself discloses their existence and id even
 * before anything hydrates the text. Requiring the list makes "which corpora
 * may this caller see" a decision someone had to write down.
 */
final readonly class SearchQuery
{
    /**
     * @param  list<DocumentType>  $types  which document types to search; never empty
     * @param  array<string, mixed>  $filters  jsonb containment predicate, applied to every branch
     * @param  array<string, list<scalar>>  $anyFilters  key ⇒ accepted values, ORed
     * @param  array<string, array{min?: int|float, max?: int|float}>  $rangeFilters  numeric bounds
     */
    private function __construct(
        public string $term,
        public array $types,
        public string $locale,
        public int $limit,
        public array $filters,
        public array $anyFilters,
        public array $rangeFilters,
    ) {}

    /**
     * @param  list<DocumentType>  $types  required; there is deliberately no "everything" default
     * @param  array<string, mixed>  $filters
     * @param  array<string, list<scalar>>  $anyFilters
     * @param  array<string, array{min?: int|float, max?: int|float}>  $rangeFilters
     *
     * @throws \InvalidArgumentException when the type list is empty
     */
    public static function make(
        string $term,
        string $locale,
        array $types,
        int $limit = 20,
        array $filters = [],
        array $anyFilters = [],
        array $rangeFilters = [],
    ): self {
        if ($types === []) {
            // An empty list is not "search everything" — it is a caller bug that
            // would otherwise produce a query with no type predicate at all.
            throw new \InvalidArgumentException('SearchQuery: at least one searchable type is required.');
        }

        return new self(
            term: trim($term),
            // DocumentType is an interface, not an enum: two instances representing
            // the same value() are not guaranteed to be the same object, or even the
            // same class, so array_unique()'s default SORT_REGULAR (==) comparison
            // cannot be trusted to dedupe them. Dedupe on value() explicitly instead.
            types: self::uniqueByValue($types),
            locale: $locale,
            limit: max(1, $limit),
            filters: $filters,
            anyFilters: array_filter($anyFilters, static fn (array $values): bool => $values !== []),
            rangeFilters: array_filter($rangeFilters, static fn (array $bounds): bool => $bounds !== []),
        );
    }

    public function hasPredicates(): bool
    {
        return $this->filters !== [] || $this->anyFilters !== [] || $this->rangeFilters !== [];
    }

    /**
     * Types queried at the requested locale.
     *
     * @return list<DocumentType>
     */
    public function translatableTypes(): array
    {
        return array_values(array_filter(
            $this->types,
            fn (DocumentType $type): bool => $type->isTranslatable(),
        ));
    }

    /**
     * Types queried at `DocumentType::LOCALE_ANY`.
     *
     * These rows were indexed with the `default` configuration rather than the
     * requested locale's, so they need their own bound `regconfig` — one
     * constant per branch, never a column reference (C9).
     *
     * @return list<DocumentType>
     */
    public function localeAnyTypes(): array
    {
        return array_values(array_filter(
            $this->types,
            fn (DocumentType $type): bool => ! $type->isTranslatable(),
        ));
    }

    public function hasTerm(): bool
    {
        return $this->term !== '';
    }

    /**
     * @param  list<DocumentType>  $types
     * @return list<DocumentType>
     */
    private static function uniqueByValue(array $types): array
    {
        $seen = [];
        $unique = [];

        foreach ($types as $type) {
            if (! in_array($type->value(), $seen, true)) {
                $seen[] = $type->value();
                $unique[] = $type;
            }
        }

        return $unique;
    }
}
