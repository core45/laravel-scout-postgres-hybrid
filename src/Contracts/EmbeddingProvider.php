<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Contracts;

/**
 * Produces the vectors the semantic branch searches over.
 *
 * The host application injects an `EmbeddingService` whose every method takes a `Shop`,
 * because model and API key are per-tenant there. That is the host's concern: an
 * implementation that varies by tenant resolves the tenant itself, and the package
 * asks only for a vector.
 *
 * Deliberately no vendor dependency. The default binding does nothing and the semantic
 * branch degrades to keyword and trigram, so the package is installable and useful with
 * no embedding API configured at all. An OpenAI implementation is documented rather than
 * shipped.
 */
interface EmbeddingProvider
{
    /**
     * @return ?list<float> null when no vector could be produced — the semantic branch
     *                      is then skipped rather than failing the whole search
     */
    public function embed(string $text): ?array;

    /**
     * Whether embedding is configured and expected to work.
     *
     * Checked before the semantic branch runs, so a provider that is merely
     * unconfigured costs no API call and no exception.
     */
    public function isReady(): bool;

    /**
     * Identifies the provider *and* the model, so stored vectors can be recognised as
     * stale.
     *
     * Written to `search_documents.embedding_fingerprint` at index time and compared at
     * read time: vectors from two different models are not comparable, and a distance
     * between them is a plausible-looking number that means nothing. Changing the model
     * MUST change this string.
     *
     * @return non-empty-string
     */
    public function fingerprint(): string;
}
