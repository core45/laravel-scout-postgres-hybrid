<?php

declare(strict_types=1);

use Core45\ScoutPostgres\DTOs\SearchQuery;
use Core45\ScoutPostgres\Exceptions\UnresolvableScope;
use Core45\ScoutPostgres\Search\PostgresSearchService;
use Core45\ScoutPostgres\Search\SearchIndexer;
use Core45\ScoutPostgres\Tests\Fixtures\ArticleType;
use Core45\ScoutPostgres\Tests\Fixtures\FixtureScope;
use Core45\ScoutPostgres\Tests\Fixtures\Models\Article;
use Core45\ScoutPostgres\Tests\Fixtures\Models\Tenant;

/**
 * SC-1 and SC-3, asserted rather than assumed.
 *
 * These are the tests the extraction exists to keep passing. Every one of them
 * fails loudly if the scope predicate is dropped from a branch — which is the
 * single most likely way this package breaks, and the one that would otherwise
 * look like "search works fine" right up until it serves another tenant's rows.
 */
beforeEach(function (): void {
    $this->acme = Tenant::query()->create(['name' => 'Acme']);
    $this->other = Tenant::query()->create(['name' => 'Other']);

    $this->acmeArticle = Article::query()->create([
        'tenant_id' => $this->acme->getKey(),
        'title' => 'Acme yoga mat',
        'body' => 'Acme body text.',
        'locale' => 'en',
        'published' => true,
    ]);

    $this->otherArticle = Article::query()->create([
        'tenant_id' => $this->other->getKey(),
        'title' => 'Other yoga mat',
        'body' => 'Other body text.',
        'locale' => 'en',
        'published' => true,
    ]);

    FixtureScope::bind((int) $this->acme->getKey());
    app(SearchIndexer::class)->reconcileModel($this->acmeArticle);

    FixtureScope::bind((int) $this->other->getKey());
    app(SearchIndexer::class)->reconcileModel($this->otherArticle);

    FixtureScope::bind((int) $this->acme->getKey());
});

it('never returns another scope\'s documents from the keyword branch', function (): void {
    $results = PostgresSearchService::for((int) $this->acme->getKey())
        ->keyword(SearchQuery::make(term: 'yoga', locale: 'en', types: [ArticleType::Article]));

    $ids = array_map(fn ($hit): int => $hit->searchableId, $results->hits);

    expect($ids)->toContain((int) $this->acmeArticle->getKey())
        ->and($ids)->not->toContain((int) $this->otherArticle->getKey());
});

it('never returns another scope\'s documents from the trigram branch', function (): void {
    $results = PostgresSearchService::for((int) $this->acme->getKey())
        ->trigram(SearchQuery::make(term: 'yoga', locale: 'en', types: [ArticleType::Article]));

    $ids = array_map(fn ($hit): int => $hit->searchableId, $results->hits);

    expect($ids)->not->toContain((int) $this->otherArticle->getKey());
});

it('never returns another scope\'s documents from the fused search', function (): void {
    $results = PostgresSearchService::for((int) $this->acme->getKey())
        ->search(SearchQuery::make(term: 'yoga', locale: 'en', types: [ArticleType::Article]));

    $ids = array_map(fn ($hit): int => $hit->searchableId, $results->hits);

    expect($ids)->not->toContain((int) $this->otherArticle->getKey());
});

it('does not let hydration cross scopes even when the hit set is forced', function (): void {
    // Search as the other tenant, then hydrate as Acme. The hit is real and its id
    // is valid, so only the hydration predicate (C3) can keep it out.
    $foreign = PostgresSearchService::for((int) $this->other->getKey())
        ->keyword(SearchQuery::make(term: 'yoga', locale: 'en', types: [ArticleType::Article]));

    $models = PostgresSearchService::for((int) $this->acme->getKey())
        ->hydrate($foreign, ArticleType::Article);

    expect($models)->toBeEmpty();
});

it('fails closed when no scope can be resolved rather than searching every scope', function (): void {
    // SC-1. The alternatives to throwing — dropping the predicate, or forcing an
    // empty result — are respectively a cross-tenant leak and a silent outage, and
    // both look identical to the caller.
    expect(fn () => PostgresSearchService::for(null))
        ->toThrow(UnresolvableScope::class);
});

it('purges only the named scope', function (): void {
    app(SearchIndexer::class)->purgeScope((int) $this->acme->getKey());

    $acme = PostgresSearchService::for((int) $this->acme->getKey())
        ->keyword(SearchQuery::make(term: 'yoga', locale: 'en', types: [ArticleType::Article]));

    $other = PostgresSearchService::for((int) $this->other->getKey())
        ->keyword(SearchQuery::make(term: 'yoga', locale: 'en', types: [ArticleType::Article]));

    expect($acme->hits)->toBeEmpty()
        ->and($other->hits)->not->toBeEmpty();
});
