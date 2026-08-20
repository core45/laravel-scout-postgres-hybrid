<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Search;

use Core45\ScoutPostgres\Contracts\DocumentType;

/**
 * The single reader of `config('scout-postgres.text_search')`.
 *
 * The locale→regconfig map has to be resolved in PHP twice, for two unrelated
 * reasons, and both break silently if the two readers disagree:
 *
 * - **Indexing.** The trigger cannot read PHP config, so the indexer writes the
 *   resolved name into `search_documents.text_search_config` and the trigger
 *   reads that column (D6).
 * - **Querying.** A tsquery whose regconfig comes from a *column* is not a
 *   constant, so the planner cannot use the GIN index at all — verified as
 *   `Seq Scan … Disabled: true` even with `enable_seqscan = off`. The config
 *   must be bound as a constant, one per CTE branch (C9).
 *
 * Changing the map therefore requires a reindex: existing vectors were built
 * with the old configuration.
 */
final class TextSearchConfig
{
    /**
     * Resolve the PostgreSQL text search configuration for a locale.
     *
     * `DocumentType::LOCALE_ANY` and any unmapped locale fall back to
     * `simple`, which does no stemming — correct for Polish, which has no
     * PostgreSQL stemmer at all (C1), and the only safe default for text whose
     * language is unknown.
     */
    public static function for(string $locale): string
    {
        /** @var array<string, string> $configs */
        $configs = config('scout-postgres.text_search.configs', []);

        /** @var string $default */
        $default = config('scout-postgres.text_search.default', 'simple');

        if ($locale === DocumentType::LOCALE_ANY) {
            return $default;
        }

        return $configs[$locale] ?? $default;
    }

    /**
     * Every configuration name the map can produce.
     *
     * Used to assert that a value read back out of the database is one this
     * application actually writes, rather than trusting a stored string on its
     * way into a `::regconfig` cast.
     *
     * @return list<string>
     */
    public static function known(): array
    {
        /** @var array<string, string> $configs */
        $configs = config('scout-postgres.text_search.configs', []);

        /** @var string $default */
        $default = config('scout-postgres.text_search.default', 'simple');

        return array_values(array_unique([$default, ...array_values($configs)]));
    }
}
