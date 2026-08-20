<?php

declare(strict_types=1);

use Core45\ScoutPostgres\Search\PostgresDocumentEngine;
use Core45\ScoutPostgres\Search\SearchIndexer;
use Core45\ScoutPostgres\Tests\Fixtures\FixtureScope;
use Core45\ScoutPostgres\Tests\Fixtures\Models\Article;
use Core45\ScoutPostgres\Tests\Fixtures\Models\Tenant;
use Laravel\Scout\EngineManager;

/**
 * The package as Scout sees it.
 *
 * The tests above call the service directly, which proves the SQL works but says
 * nothing about whether an adopter can reach it. This file is the difference
 * between "the code is correct" and "the driver is installed".
 */
beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Acme']);
    FixtureScope::bind((int) $this->tenant->getKey());

    $this->article = Article::query()->create([
        'tenant_id' => $this->tenant->getKey(),
        'title' => 'Yoga mat for exercises',
        'body' => 'A thick mat suitable for yoga.',
        'locale' => 'en',
        'published' => true,
    ]);

    app(SearchIndexer::class)->reconcileModel($this->article);
});

it('registers itself as the postgres scout driver', function (): void {
    expect(app(EngineManager::class)->engine())->toBeInstanceOf(PostgresDocumentEngine::class);
});

it('returns the model through Model::search(), which is the whole public surface', function (): void {
    $results = Article::search('yoga')->options(['scope' => (int) $this->tenant->getKey()])->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->getKey())->toBe($this->article->getKey());
});

it('paginates through the database rather than pretending the corpus has offsets', function (): void {
    $page = Article::search('yoga')
        ->options(['scope' => (int) $this->tenant->getKey()])
        ->paginate(10);

    expect($page->total())->toBe(1)
        ->and($page->items()[0]->getKey())->toBe($this->article->getKey());
});

it('refuses within(), because this engine has no named indexes', function (): void {
    expect(fn () => Article::search('yoga')->within('some_index')->get())
        ->toThrow(LogicException::class);
});
