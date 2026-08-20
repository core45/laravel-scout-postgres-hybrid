<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Tests\Fixtures;

use Core45\ScoutPostgres\Contracts\EmbeddingProvider;

/**
 * `FakeEmbeddings` under a second model identity.
 *
 * Stands for the one deployment that actually happens: an embedding model
 * upgraded in place, same dimension count, while the corpus still holds vectors
 * written by the previous model. The only difference from `FakeEmbeddings` is
 * `fingerprint()`.
 *
 * That sameness is the point. If this provider embedded differently — a
 * different algorithm, a different dimension count, a null return — the semantic
 * branch would come back empty for a reason that has nothing to do with the
 * `embedding_fingerprint` predicate, and a test asserting "no results" would
 * pass while proving nothing. Delegating to `FakeEmbeddings` keeps the vectors
 * byte-identical, so an empty result set can only mean the fingerprint predicate
 * excluded the rows.
 */
final class StaleEmbeddings implements EmbeddingProvider
{
    private readonly FakeEmbeddings $delegate;

    public function __construct(int $dimensions = 1536)
    {
        $this->delegate = new FakeEmbeddings($dimensions);
    }

    /**
     * @return ?list<float>
     */
    public function embed(string $text): ?array
    {
        return $this->delegate->embed($text);
    }

    public function isReady(): bool
    {
        return true;
    }

    public function fingerprint(): string
    {
        return 'fake:v2';
    }
}
