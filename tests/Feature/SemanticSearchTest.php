<?php

declare(strict_types=1);

use Core45\ScoutPostgres\Contracts\EmbeddingProvider;
use Core45\ScoutPostgres\DTOs\SearchQuery;
use Core45\ScoutPostgres\Scope\CrossScope;
use Core45\ScoutPostgres\Search\PostgresSearchService;
use Core45\ScoutPostgres\Search\SearchIndexer;
use Core45\ScoutPostgres\Search\SearchIndexStatistics;
use Core45\ScoutPostgres\Tests\Fixtures\ArticleType;
use Core45\ScoutPostgres\Tests\Fixtures\FakeEmbeddings;
use Core45\ScoutPostgres\Tests\Fixtures\FixtureScope;
use Core45\ScoutPostgres\Tests\Fixtures\Models\Article;
use Core45\ScoutPostgres\Tests\Fixtures\Models\Tenant;

/**
 * The third branch, and the fusion that is this package's actual differentiator.
 *
 * Everything else in the suite runs with the shipped NullEmbeddingProvider, which
 * reports itself unready — so without this file the pgvector SQL would never
 * execute and `search()` would only ever fuse two branches, while CI reported
 * green on the claim that it fuses three.
 */
beforeEach(function (): void {
    app()->instance(EmbeddingProvider::class, new FakeEmbeddings);

    $this->tenant = Tenant::query()->create(['name' => 'Acme']);
    FixtureScope::bind((int) $this->tenant->getKey());
});

function indexFor(string $title, string $body, Tenant $tenant): Article
{
    $article = Article::query()->create([
        'tenant_id' => $tenant->getKey(),
        'title' => $title,
        'body' => $body,
        'locale' => 'en',
        'published' => true,
    ]);

    app(SearchIndexer::class)->reconcileModel($article);

    return $article;
}

it('writes an embedding when a provider is bound', function (): void {
    indexFor('Yoga mat', 'A thick mat.', $this->tenant);

    $embedded = app(SearchIndexStatistics::class)->withEmbedding(
        CrossScope::platformWide('suite asserts vectors were written'),
    );

    expect($embedded)->toBe(1);
});

it('runs the pgvector branch and returns the nearer document first', function (): void {
    $exact = indexFor('Yoga mat for exercises', 'Thick mat.', $this->tenant);
    indexFor('Industrial gearbox bearing', 'Heavy machinery part.', $this->tenant);

    $results = PostgresSearchService::for((int) $this->tenant->getKey())
        ->semantic(SearchQuery::make(
            term: 'Yoga mat for exercises',
            locale: 'en',
            types: [ArticleType::Article],
        ));

    // The deterministic provider embeds identical text to an identical vector, so
    // the exact-title row is at cosine distance zero and must rank first.
    expect($results->hits)->not->toBeEmpty()
        ->and($results->hits[0]->searchableId)->toBe((int) $exact->getKey());
});

it('fuses all three branches into one ranking', function (): void {
    $article = indexFor('Yoga mat for exercises', 'A thick mat suitable for yoga.', $this->tenant);

    $results = PostgresSearchService::for((int) $this->tenant->getKey())
        ->search(SearchQuery::make(
            term: 'Yoga mat for exercises',
            locale: 'en',
            types: [ArticleType::Article],
        ));

    $hit = collect($results->hits)->firstWhere('searchableId', (int) $article->getKey());

    // This is the claim the README leads with: three branches ranked against each
    // other by RRF, not concatenated. A row matched by all three must say so.
    expect($hit)->not->toBeNull()
        ->and($hit->sources)->toContain('keyword')
        ->and($hit->sources)->toContain('trigram')
        ->and($hit->sources)->toContain('semantic');
});

it('does not leak another scope\'s documents through the semantic branch', function (): void {
    $other = Tenant::query()->create(['name' => 'Other']);

    FixtureScope::bind((int) $other->getKey());
    $foreign = indexFor('Yoga mat for exercises', 'Thick mat.', $other);

    FixtureScope::bind((int) $this->tenant->getKey());
    indexFor('Yoga mat for exercises', 'Thick mat.', $this->tenant);

    $results = PostgresSearchService::for((int) $this->tenant->getKey())
        ->semantic(SearchQuery::make(
            term: 'Yoga mat for exercises',
            locale: 'en',
            types: [ArticleType::Article],
        ));

    $ids = array_map(fn ($hit): int => $hit->searchableId, $results->hits);

    // Vector similarity needs no shared keyword to surface a neighbour's row, so
    // this is the branch where a dropped scope predicate leaks most quietly.
    expect($ids)->not->toContain((int) $foreign->getKey());
});

it('drops the stored vector when the text changes, rather than ranking against a stale one', function (): void {
    $article = indexFor('Yoga mat', 'Original body.', $this->tenant);

    $article->update(['title' => 'Completely different subject']);
    app(SearchIndexer::class)->reconcileModel($article);

    $embedded = app(SearchIndexStatistics::class)->withEmbedding(
        CrossScope::platformWide('suite asserts staleness handling'),
    );

    // reconcile() nulls the embedding whenever content_hash changes: a vector for
    // the old text is not merely outdated, it ranks the row against text it no
    // longer contains.
    expect($embedded)->toBe(0);
});
