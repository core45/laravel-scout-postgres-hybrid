<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\DTOs;

use Core45\ScoutPostgres\Contracts\DocumentType;

/**
 * One row destined for `search_documents`, as produced by a model's
 * `toSearchDocuments()`.
 *
 * Deliberately *not* a full row: the scope column, `text_search_config`,
 * `trigram_text` and `search_vector` are all resolved by the indexer or the
 * database, never by the model.
 *
 * - the scope column comes from the scope the indexer is bound to, so a model
 *   cannot name a tenant it does not belong to.
 * - `text_search_config` comes from `TextSearchConfig::for($locale)`, so the
 *   locale map has exactly one reader (D6).
 * - `trigram_text` is normalised by PostgreSQL's own `f_unaccent(lower(...))`
 *   from `$trigramSource`, so the index and the query cannot drift apart the
 *   way a PHP-side transliteration would (C3). The model supplies the raw
 *   source string only.
 *
 * `$body` is capped on construction: `to_tsvector` errors above 1MB and
 * silently drops lexeme positions past 16383, which degrades `ts_rank_cd`
 * without any error (D9).
 */
final readonly class SearchDocumentData
{
    /**
     * @param  string  $trigramSource  Short, identifier-ish text only — title, SKU,
     *                                 names. Never body: `word_similarity` against a
     *                                 5000-char description is unreachable (C2).
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public DocumentType $searchableType,
        public int $searchableId,
        public string $locale,
        public string $title,
        public string $body,
        public string $trigramSource,
        public array $filters = [],
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public static function make(
        DocumentType $searchableType,
        int $searchableId,
        string $locale,
        string $title,
        string $body = '',
        ?string $trigramSource = null,
        array $filters = [],
    ): self {
        return new self(
            searchableType: $searchableType,
            searchableId: $searchableId,
            locale: $locale,
            title: self::collapseWhitespace($title),
            body: self::capBody($body),
            trigramSource: self::collapseWhitespace($trigramSource ?? $title),
            filters: $filters,
        );
    }

    /**
     * The sha256 of the resolved text, used to skip re-embedding unchanged
     * documents (D10). Pairs with `embedding_fingerprint`, which covers the
     * provider/model half that this hash cannot see (D3).
     */
    public function contentHash(): string
    {
        // Inlined rather than delegated to a host `SearchDocument` model, which the
        // package does not have. The algorithm must stay byte-identical to the host
        // application's `SearchDocument::hashContent()` — hash('sha256', $title."\0".$body)
        // — because changing it invalidates content_hash for every already-indexed row.
        return hash('sha256', $this->title."\0".$this->body);
    }

    /**
     * The stable identity of this document within its scope. Two data objects
     * with the same key address the same row.
     */
    public function key(): string
    {
        return $this->searchableType->value().':'.$this->searchableId.':'.$this->locale;
    }

    private static function capBody(string $body): string
    {
        $body = self::collapseWhitespace($body);

        $limit = (int) config('scout-postgres.max_body_length', 100_000);

        if ($limit > 0 && mb_strlen($body) > $limit) {
            return mb_substr($body, 0, $limit);
        }

        return $body;
    }

    private static function collapseWhitespace(string $text): string
    {
        // Tags become a space rather than nothing. `strip_tags` alone turns
        // `<p>Alpha</p><p>Beta</p>` into `AlphaBeta` — one lexeme that matches
        // neither word, in every rich-text description in the catalogue.
        //
        // The pattern requires a tag-like opener (`<x`, `</x`, `<!`) rather than
        // matching any `<…>` span, because a span-matcher eats prose:
        // `pressure < 10 bar and temp > 40C` collapses to `pressure 40C`, and
        // the words in between vanish from the body, the tsvector and the
        // content hash with no error anywhere.
        $text = (string) preg_replace('#</?[a-zA-Z!][^<>]*+>#u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($text)));
    }
}
