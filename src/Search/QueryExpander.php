<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Search;

/**
 * Applies `config('scout-postgres.synonyms')` as query expansion.
 *
 * This is the **only** mechanism that relates `health` to `zdrowie`. Neither
 * stemming nor trigrams can derive it — the two strings share no lexemes and no
 * trigrams — so a pair that is not listed in config does not exist as far as
 * search is concerned (C8).
 *
 * The output is a **list of alternative queries**, not one longer query, and
 * that distinction is load-bearing. `websearch_to_tsquery('english', 'yoga
 * joga')` compiles to `'yoga' & 'joga'` — a space is AND — so appending
 * synonyms to the term makes recall strictly *worse* than not expanding at all.
 * The caller ORs the variants together (`tsquery || tsquery`).
 *
 * Substitution is one token at a time rather than a cartesian product. A
 * two-synonym-bearing three-word query would otherwise produce dozens of
 * variants, each its own `websearch_to_tsquery` call and its own index probe,
 * for terms that are already covered by the single-substitution variants.
 */
final class QueryExpander
{
    /**
     * Upper bound on variants handed to SQL, original included.
     *
     * Each variant is one more `websearch_to_tsquery(...)` in the OR chain and
     * one more `%>` probe, so this caps query width rather than result quality:
     * synonym lists here are short and the cap is only reached by a query that
     * carries several expandable words at once.
     */
    private const int MAX_VARIANTS = 8;

    /**
     * Every form of the term worth searching, original first.
     *
     * @return list<string>
     */
    public function expand(string $term): array
    {
        $term = trim($term);

        if ($term === '') {
            return [];
        }

        $variants = [$term];

        // preg_split with PREG_SPLIT_OFFSET_CAPTURE so a token can be replaced
        // in place, preserving the rest of the query verbatim — including
        // punctuation and any websearch operators the user typed.
        $tokens = preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY | PREG_SPLIT_OFFSET_CAPTURE);

        if ($tokens === false) {
            return $variants;
        }

        $synonyms = $this->synonyms();

        foreach ($tokens as [$token, $offset]) {
            $key = mb_strtolower($token);

            foreach ($synonyms[$key] ?? [] as $synonym) {
                if (count($variants) >= self::MAX_VARIANTS) {
                    return $variants;
                }

                $variant = substr_replace($term, $synonym, $offset, strlen($token));

                if (! in_array($variant, $variants, true)) {
                    $variants[] = $variant;
                }
            }
        }

        return $variants;
    }

    /**
     * The configured map, keyed by lower-cased word.
     *
     * Lower-casing the keys here means a config author does not have to think
     * about case, and a capitalised query word still expands.
     *
     * @return array<string, list<string>>
     */
    private function synonyms(): array
    {
        /** @var array<string, list<string>> $configured */
        $configured = config('scout-postgres.synonyms', []);

        $map = [];

        foreach ($configured as $word => $alternatives) {
            $map[mb_strtolower((string) $word)] = array_values(array_filter(
                $alternatives,
                static fn (string $alternative): bool => trim($alternative) !== '',
            ));
        }

        return $map;
    }
}
