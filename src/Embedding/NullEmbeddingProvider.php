<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Embedding;

use Core45\ScoutPostgres\Contracts\EmbeddingProvider;

/**
 * The default provider: no embeddings at all.
 *
 * Bound unless the adopter binds their own, so the package installs and works
 * with no embedding API configured. `isReady()` returning false is what the
 * semantic branch checks, so searches fall back to keyword plus trigram rather
 * than failing — a two-branch hybrid, which is still more than most Postgres
 * Scout drivers offer.
 *
 * The fingerprint is a real, stable string rather than an empty one. Rows written
 * while this provider was bound carry it, so swapping in a real provider later
 * makes every one of them recognisably stale and eligible for re-embedding, which
 * an empty fingerprint could not express.
 */
final class NullEmbeddingProvider implements EmbeddingProvider
{
    /**
     * @return ?list<float>
     */
    public function embed(string $text): ?array
    {
        return null;
    }

    public function isReady(): bool
    {
        return false;
    }

    public function fingerprint(): string
    {
        return 'null:none';
    }
}
