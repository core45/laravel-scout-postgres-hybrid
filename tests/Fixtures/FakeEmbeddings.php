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
 * Deterministic rather than random, and derived from the text rather than from a
 * counter, so the same string always embeds to the same vector and two different
 * strings reliably do not. That is enough for a distance assertion to mean
 * something without depending on a network call or an API key.
 */
final class FakeEmbeddings implements EmbeddingProvider
{
    public function __construct(private readonly int $dimensions = 1536) {}

    /**
     * @return ?list<float>
     */
    public function embed(string $text): ?array
    {
        $normalized = mb_strtolower(trim($text));

        if ($normalized === '') {
            return null;
        }

        // A hash-seeded unit-ish vector. Texts sharing a prefix land nearer each
        // other than unrelated ones, which is all the ordering assertions need.
        $seed = crc32($normalized);
        $vector = [];

        for ($i = 0; $i < $this->dimensions; $i++) {
            $seed = ($seed * 1103515245 + 12345) & 0x7FFFFFFF;
            $vector[] = ($seed / 0x7FFFFFFF) - 0.5;
        }

        return $vector;
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
