<?php

declare(strict_types=1);

use Core45\ScoutPostgres\Contracts\EmbeddingProvider;
use Core45\ScoutPostgres\DTOs\SearchQuery;
use Core45\ScoutPostgres\Embedding\EmbeddingBackfill;
use Core45\ScoutPostgres\Search\PostgresSearchService;
use Core45\ScoutPostgres\Search\SearchIndexer;
use Core45\ScoutPostgres\Tests\Fixtures\ArticleType;
use Core45\ScoutPostgres\Tests\Fixtures\FakeEmbeddings;
use Core45\ScoutPostgres\Tests\Fixtures\FixtureScope;
use Core45\ScoutPostgres\Tests\Fixtures\Models\Article;
use Core45\ScoutPostgres\Tests\Fixtures\Models\Tenant;
use Core45\ScoutPostgres\Tests\Fixtures\StaleEmbeddings;

/**
 * The model upgrade nothing else in the suite covers.
 *
 * `SemanticSearchTest` proves the semantic branch finds a document whose vector
 * this provider wrote. It cannot see the failure that matters in production:
 * embedding model v2 deployed with the same dimension count, over a corpus whose
 * rows still carry v1 vectors. Those rows have a vector, they are in scope, they
 * are the right type and locale — and a cosine distance between two models'
 * spaces is a plausible-looking number that means nothing, so the ranking is
 * silently garbage until the backfill catches up.
 *
 * Every test here asserts the positive control first. "Returns nothing" is
 * unfalsifiable on its own: a typo in the query, a missing backfill or a broken
 * fixture all produce it too.
 */
beforeEach(function (): void {
    app()->instance(EmbeddingProvider::class, new FakeEmbeddings);

    $this->tenant = Tenant::query()->create(['name' => 'Acme']);
    FixtureScope::bind((int) $this->tenant->getKey());
});

function indexAndEmbedForStaleness(string $title, string $body, Tenant $tenant): Article
{
    $article = Article::query()->create([
        'tenant_id' => $tenant->getKey(),
        'title' => $title,
        'body' => $body,
        'locale' => 'en',
        'published' => true,
    ]);

    app(SearchIndexer::class)->reconcileModel($article);

    // The indexer never writes vectors; the backfill does, and stamps the
    // provider's fingerprint alongside each one.
    app(EmbeddingBackfill::class)->run((int) $tenant->getKey());

    return $article;
}

function stalenessQuery(): SearchQuery
{
    return SearchQuery::make(
        term: 'Yoga mat for exercises',
        locale: 'en',
        types: [ArticleType::Article],
    );
}

it('stops serving a vector written by another model through the pure semantic path', function (): void {
    $article = indexAndEmbedForStaleness('Yoga mat for exercises', 'Thick mat.', $this->tenant);

    $service = PostgresSearchService::for((int) $this->tenant->getKey());

    // Positive control: under the fingerprint the rows were written with, this
    // exact query does find the document.
    $before = $service->semantic(stalenessQuery());

    expect($before->hits)->not->toBeEmpty()
        ->and($before->hits[0]->searchableId)->toBe((int) $article->getKey());

    // The upgrade. Same dimensions, same vectors, different model identity — and
    // no backfill has run, so every stored row still carries `fake:v1`.
    app()->instance(EmbeddingProvider::class, new StaleEmbeddings);

    $after = PostgresSearchService::for((int) $this->tenant->getKey())
        ->semantic(stalenessQuery());

    expect($after->hits)->toBeEmpty();
});

it('drops the semantic branch of a fused query when the stored fingerprint is another model\'s', function (): void {
    $article = indexAndEmbedForStaleness('Yoga mat for exercises', 'Thick mat.', $this->tenant);

    $before = PostgresSearchService::for((int) $this->tenant->getKey())
        ->hybrid(stalenessQuery());

    $beforeHit = collect($before->hits)->firstWhere('searchableId', (int) $article->getKey());

    // Positive control on the *raw* branch this time: `hybrid()` compiles the
    // semantic CTE itself rather than delegating to SemanticSearchService, so
    // this is a second, independent query path with its own bindings.
    expect($beforeHit)->not->toBeNull()
        ->and($beforeHit->sources)->toContain('semantic');

    app()->instance(EmbeddingProvider::class, new StaleEmbeddings);

    $after = PostgresSearchService::for((int) $this->tenant->getKey())
        ->hybrid(stalenessQuery());

    $afterHit = collect($after->hits)->firstWhere('searchableId', (int) $article->getKey());

    // The lexical branches still match, so the document is still found — the
    // degradation is exactly the documented one, keyword plus trigram. What must
    // not survive is the semantic contribution to its score.
    expect($afterHit)->not->toBeNull()
        ->and($afterHit->sources)->not->toContain('semantic');
});

it('serves the document again once the backfill has re-embedded it under the new fingerprint', function (): void {
    $article = indexAndEmbedForStaleness('Yoga mat for exercises', 'Thick mat.', $this->tenant);

    app()->instance(EmbeddingProvider::class, new StaleEmbeddings);

    // The predicate must exclude stale rows, not permanently blacklist them.
    // EmbeddingBackfill::pending() already treats a differing fingerprint as
    // "needs embedding", so this is the pair working as one mechanism.
    app(EmbeddingBackfill::class)->run((int) $this->tenant->getKey());

    $results = PostgresSearchService::for((int) $this->tenant->getKey())
        ->semantic(stalenessQuery());

    expect($results->hits)->not->toBeEmpty()
        ->and($results->hits[0]->searchableId)->toBe((int) $article->getKey());
});
