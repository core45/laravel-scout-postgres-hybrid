<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Contracts;

use Core45\ScoutPostgres\DTOs\SearchDocumentData;

/**
 * A model that produces rows in `search_documents`.
 *
 * Replaces Scout's `toSearchableArray()` / `shouldBeSearchable()` pair. The
 * differences that matter:
 *
 * - One model produces *many* documents, one per locale, because translations
 *   live in per-locale rows rather than one flattened blob (D10).
 * - A model that should not be indexed returns `[]` rather than implementing a
 *   separate predicate. The indexer treats an empty return as "delete every row
 *   for this model", so unpublishing and deleting converge on the same state
 *   (D8).
 */
interface SearchIndexable
{
    /**
     * Build every document this model contributes, for every locale configured
     * for the current scope.
     *
     * Text must be **fallback-resolved** — whatever the storefront would
     * actually render for that locale, via `translateOrDefault($locale)`, not
     * the raw translation row. A `pl`-only product stays findable on `/en` by
     * its Polish name, exactly as it is browsable there (D10).
     *
     * @return list<SearchDocumentData>
     */
    public function toSearchDocuments(): array;
}
