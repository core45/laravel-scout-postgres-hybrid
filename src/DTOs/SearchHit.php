<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\DTOs;

use Core45\ScoutPostgres\Contracts\DocumentType;

/**
 * One matched document.
 *
 * Deliberately not a `SearchDocument` model instance. A hit is a *result*, and
 * handing callers a live Eloquent model would hand them a query surface on
 * `search_documents` — which D11 forbids outside `app/Services/Search`. The
 * source model is fetched separately, tenant-pinned, by the service.
 *
 * `$score` is only comparable within one result set: `ts_rank_cd`,
 * `word_similarity` and cosine similarity are three different scales, and the
 * hybrid path replaces all of them with a reciprocal-rank sum.
 */
final readonly class SearchHit
{
    /**
     * @param  list<string>  $sources  which branches matched: keyword, trigram, semantic
     */
    public function __construct(
        public DocumentType $searchableType,
        public int $searchableId,
        public string $locale,
        public float $score,
        public ?string $title = null,
        public array $sources = [],
    ) {}

    /**
     * DocumentType has no `from(string $value)` equivalent to `SearchableType::from()`:
     * it is an interface, not an enum, so the package cannot construct an instance
     * from a stored discriminator alone. The caller supplies the candidate type set —
     * normally the same `SearchQuery::$types` the row came from — and this resolves
     * the row's `searchable_type` against it by value().
     *
     * @param  array<string, mixed>  $row
     * @param  list<DocumentType>  $types  candidate types to match the row against
     *
     * @throws \InvalidArgumentException when no type in $types matches the row
     */
    public static function fromRow(array $row, array $types): self
    {
        /** @var list<string> $sources */
        $sources = isset($row['sources']) && is_string($row['sources'])
            ? array_values(array_filter(explode(',', $row['sources'])))
            : [];

        $value = (string) $row['searchable_type'];

        $searchableType = null;

        foreach ($types as $type) {
            if ($type->value() === $value) {
                $searchableType = $type;

                break;
            }
        }

        if ($searchableType === null) {
            throw new \InvalidArgumentException(sprintf(
                'SearchHit::fromRow(): no DocumentType in the supplied list matches searchable_type "%s".',
                $value,
            ));
        }

        return new self(
            searchableType: $searchableType,
            searchableId: (int) $row['searchable_id'],
            locale: (string) $row['locale'],
            score: (float) $row['score'],
            title: isset($row['title']) ? (string) $row['title'] : null,
            sources: $sources,
        );
    }

    public function key(): string
    {
        return $this->searchableType->value().':'.$this->searchableId.':'.$this->locale;
    }
}
