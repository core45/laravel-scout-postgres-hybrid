<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Tests\Fixtures;

use Core45\ScoutPostgres\Contracts\EmbeddingProvider;

/**
 * A deterministic stand-in for a real embedding API.
 *
 * Without this the semantic branch never executes: the shipped default provider
 * reports itself unready, so every test would pass through the two-branch
 * degraded path and the pgvector SQL — the package's actual differentiator —
 * would go unproven while CI reported green.
 *
 * A hashed bag of words rather than random noise, because the distance has to
 * carry meaning. A first attempt seeded a PRNG from the whole string, which made
 * every distinct text mutually orthogonal — so a query never came within
 * `min_similarity` of the document it was meant to match and the semantic branch
 * correctly returned nothing. Bucketing tokens instead gives texts that share
 * words a genuinely small cosine distance, which is the property these tests
 * assert on.
 *
 * Normalised to unit length so cosine distance behaves the way pgvector's `<=>`
 * expects, and deterministic so the same text always embeds identically.
 */
final class FakeEmbeddings implements EmbeddingProvider
{
    public function __construct(private readonly int $dimensions = 1536) {}

    /**
     * @return ?list<float>
     */
    public function embed(string $text): ?array
    {
        $tokens = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text), -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false || $tokens === []) {
            return null;
        }

        $vector = array_fill(0, $this->dimensions, 0.0);

        foreach ($tokens as $token) {
            $vector[crc32($token) % $this->dimensions] += 1.0;
        }

        $magnitude = sqrt(array_sum(array_map(static fn (float $v): float => $v * $v, $vector)));

        if ($magnitude === 0.0) {
            return null;
        }

        return array_map(static fn (float $v): float => $v / $magnitude, $vector);
    }

    public function isReady(): bool
    {
        return true;
    }

    public function fingerprint(): string
    {
        return 'fake:v1';
    }
}
