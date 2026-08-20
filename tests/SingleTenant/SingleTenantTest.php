<?php

declare(strict_types=1);

use Core45\ScoutPostgres\DTOs\SearchQuery;
use Core45\ScoutPostgres\Scope\ScopeDefinition;
use Core45\ScoutPostgres\Search\PostgresSearchService;
use Core45\ScoutPostgres\Search\SearchIndexer;
use Core45\ScoutPostgres\Tests\Fixtures\ArticleType;
use Core45\ScoutPostgres\Tests\Fixtures\Models\Article;
use Illuminate\Support\Facades\Schema;

/**
 * The same engine with `scope.mode => 'none'`.
 *
 * This file is why the abstraction is testable at all: under this mode the table
 * has no scope column, so any branch that still emits a scope predicate raises a
 * SQL error rather than quietly returning the wrong rows.
 */
it('creates no scope column at all', function (): void {
    expect(Schema::hasColumn('search_documents', 'tenant_id'))->toBeFalse()
        ->and(app(ScopeDefinition::class)->isScoped())->toBeFalse();
});

it('indexes and finds a model with no scope anywhere', function (): void {
    $article = Article::query()->create([
        'title' => 'Yoga mat for exercises',
        'body' => 'A thick mat suitable for yoga.',
        'locale' => 'en',
        'published' => true,
    ]);

    app(SearchIndexer::class)->reconcileModel($article);

    $results = PostgresSearchService::for(null)
        ->keyword(SearchQuery::make(term: 'yoga', locale: 'en', types: [ArticleType::Article]));

    expect($results->hits)->not->toBeEmpty()
        ->and($results->hits[0]->searchableId)->toBe((int) $article->getKey());
});

it('runs the fused search with no scope predicate', function (): void {
    $article = Article::query()->create([
        'title' => 'Yoga mat',
        'body' => 'Body.',
        'locale' => 'en',
        'published' => true,
    ]);

    app(SearchIndexer::class)->reconcileModel($article);

    $results = PostgresSearchService::for(null)
        ->search(SearchQuery::make(term: 'yoga', locale: 'en', types: [ArticleType::Article]));

    expect($results->hits)->not->toBeEmpty();
});

it('hydrates without filtering the source table on a column it does not have', function (): void {
    $article = Article::query()->create([
        'title' => 'Yoga mat',
        'body' => 'Body.',
        'locale' => 'en',
        'published' => true,
    ]);

    app(SearchIndexer::class)->reconcileModel($article);

    $service = PostgresSearchService::for(null);
    $results = $service->keyword(SearchQuery::make(term: 'yoga', locale: 'en', types: [ArticleType::Article]));

    expect($service->hydrate($results, ArticleType::Article))->toHaveCount(1);
});

it('names the lookup index the same in both modes, degraded to two columns', function (): void {
    $indexes = collect(Schema::getIndexes('search_documents'))
        ->firstWhere('name', 'search_documents_lookup_idx');

    // D1: the composite loses its leading scope column here, but keeps its name,
    // so nothing downstream has to know which mode built the table.
    expect($indexes)->not->toBeNull()
        ->and($indexes['columns'])->toBe(['searchable_type', 'locale']);
});
