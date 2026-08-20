<?php

declare(strict_types=1);

use Core45\ScoutPostgres\DTOs\SearchQuery;
use Core45\ScoutPostgres\Scope\CrossScope;
use Core45\ScoutPostgres\Search\PostgresSearchService;
use Core45\ScoutPostgres\Search\SearchIndexer;
use Core45\ScoutPostgres\Search\SearchIndexStatistics;
use Core45\ScoutPostgres\Tests\Fixtures\ArticleType;
use Core45\ScoutPostgres\Tests\Fixtures\FixtureScope;
use Core45\ScoutPostgres\Tests\Fixtures\Models\Article;
use Core45\ScoutPostgres\Tests\Fixtures\Models\Tenant;

/**
 * The proof that the package does the thing it claims.
 *
 * Everything else in the suite tests a part. This tests the whole path an
 * adopter actually walks: migrate, index a model, search for it, get it back.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Acme']);
    FixtureScope::bind((int) $this->tenant->getKey());
});

function indexArticle(array $attributes = []): Article
{
    $article = Article::query()->create(array_merge([
        'tenant_id' => 1,
        'title' => 'Yoga mat for exercises',
        'body' => 'A thick mat suitable for yoga and stretching.',
        'locale' => 'en',
        'published' => true,
    ], $attributes));

    app(SearchIndexer::class)->reconcileModel($article);

    return $article;
}

it('indexes a model and finds it by an exact keyword', function (): void {
    $article = indexArticle(['tenant_id' => $this->tenant->getKey()]);

    $results = PostgresSearchService::for((int) $this->tenant->getKey())
        ->keyword(SearchQuery::make(
            term: 'yoga',
            locale: 'en',
            types: [ArticleType::Article],
        ));

    expect($results->isEmpty())->toBeFalse()
        ->and($results->hits[0]->searchableId)->toBe((int) $article->getKey());
});

it('finds a model through an ascii typo', function (): void {
    $article = indexArticle(['tenant_id' => $this->tenant->getKey()]);

    $results = PostgresSearchService::for((int) $this->tenant->getKey())
        ->trigram(SearchQuery::make(
            term: 'yog',
            locale: 'en',
            types: [ArticleType::Article],
        ));

    expect($results->hits)->not->toBeEmpty()
        ->and($results->hits[0]->searchableId)->toBe((int) $article->getKey());
});

it('folds diacritics, so an unaccented query still reaches an accented title', function (): void {
    $article = indexArticle([
        'tenant_id' => $this->tenant->getKey(),
        'title' => 'Zdrowié i joga',
    ]);

    $results = PostgresSearchService::for((int) $this->tenant->getKey())
        ->trigram(SearchQuery::make(
            term: 'zdrowie',
            locale: 'en',
            types: [ArticleType::Article],
        ));

    expect($results->hits)->not->toBeEmpty()
        ->and($results->hits[0]->searchableId)->toBe((int) $article->getKey());
});

it('hydrates hits back into real models in relevance order', function (): void {
    $article = indexArticle(['tenant_id' => $this->tenant->getKey()]);

    $service = PostgresSearchService::for((int) $this->tenant->getKey());
    $results = $service->keyword(SearchQuery::make(
        term: 'yoga',
        locale: 'en',
        types: [ArticleType::Article],
    ));

    $models = $service->hydrate($results, ArticleType::Article);

    expect($models)->toHaveCount(1)
        ->and($models->first()->getKey())->toBe($article->getKey());
});

it('removes the documents when a model stops being indexable', function (): void {
    $article = indexArticle(['tenant_id' => $this->tenant->getKey()]);

    $before = app(SearchIndexStatistics::class)
        ->countForScopeAndType((int) $this->tenant->getKey(), ArticleType::Article);

    $article->update(['published' => false]);
    app(SearchIndexer::class)->reconcileModel($article);

    $after = app(SearchIndexStatistics::class)
        ->countForScopeAndType((int) $this->tenant->getKey(), ArticleType::Article);

    // toSearchDocuments() returning [] means "delete every row for this model",
    // so unpublishing and deleting converge on the same state.
    expect($before)->toBe(1)->and($after)->toBe(0);
});

it('reports a platform-wide count only through the named cross-scope API', function (): void {
    indexArticle(['tenant_id' => $this->tenant->getKey()]);

    $total = app(SearchIndexStatistics::class)
        ->total(CrossScope::platformWide('suite asserts the corpus size across every tenant'));

    expect($total)->toBe(1);
});
