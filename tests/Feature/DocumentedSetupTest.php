<?php

declare(strict_types=1);

use Core45\ScoutPostgres\Contracts\DocumentTypeRegistry;
use Core45\ScoutPostgres\Jobs\SyncSearchDocumentJob;
use Core45\ScoutPostgres\Observers\SearchDocumentObserver;
use Core45\ScoutPostgres\Scope\CrossScope;
use Core45\ScoutPostgres\Search\SearchIndexer;
use Core45\ScoutPostgres\Search\SearchIndexStatistics;
use Core45\ScoutPostgres\Tests\Fixtures\ArticleType;
use Core45\ScoutPostgres\Tests\Fixtures\FixtureScope;
use Core45\ScoutPostgres\Tests\Fixtures\Models\Article;
use Core45\ScoutPostgres\Tests\Fixtures\Models\Tenant;
use Illuminate\Support\Facades\Queue;

/**
 * The path the README actually tells an adopter to walk.
 *
 * The service-level tests prove the SQL is right; they say nothing about whether
 * the documented setup works. The observer takes two injected dependencies, so a
 * container-resolution failure here would meet every adopter on step one of the
 * install instructions while the rest of the suite stayed green.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Acme']);
    FixtureScope::bind((int) $this->tenant->getKey());
});

it('resolves the observer from the container, as the README instructs', function (): void {
    expect(app(SearchDocumentObserver::class))->toBeInstanceOf(SearchDocumentObserver::class);
});

it('dispatches a sync job when an observed model is saved', function (): void {
    Queue::fake();
    Article::observe(SearchDocumentObserver::class);

    $article = Article::query()->create([
        'tenant_id' => $this->tenant->getKey(),
        'title' => 'Yoga mat',
        'body' => 'Body.',
        'locale' => 'en',
        'published' => true,
    ]);

    Queue::assertPushed(
        SyncSearchDocumentJob::class,
        fn (SyncSearchDocumentJob $job): bool => $job->searchableId === (int) $article->getKey()
            && $job->searchableType === ArticleType::Article->value()
            && $job->scope === (int) $this->tenant->getKey(),
    );
});

it('indexes the model when that job actually runs', function (): void {
    $article = Article::query()->create([
        'tenant_id' => $this->tenant->getKey(),
        'title' => 'Yoga mat',
        'body' => 'Body.',
        'locale' => 'en',
        'published' => true,
    ]);

    $job = new SyncSearchDocumentJob(
        ArticleType::Article->value(),
        (int) $article->getKey(),
        (int) $this->tenant->getKey(),
    );

    $job->handle(app(SearchIndexer::class), app(DocumentTypeRegistry::class));

    expect(app(SearchIndexStatistics::class)->total(CrossScope::platformWide('suite')))->toBe(1);
});

it('discards a job whose type is no longer registered instead of retrying forever', function (): void {
    $job = new SyncSearchDocumentJob('a-type-nobody-registered', 1, (int) $this->tenant->getKey());

    $job->handle(app(SearchIndexer::class), app(DocumentTypeRegistry::class));

    expect(app(SearchIndexStatistics::class)->total(CrossScope::platformWide('suite')))->toBe(0);
});

it('rebuilds the corpus through the reindex command', function (): void {
    Article::query()->create([
        'tenant_id' => $this->tenant->getKey(),
        'title' => 'Yoga mat',
        'body' => 'Body.',
        'locale' => 'en',
        'published' => true,
    ]);

    $this->artisan('scout-postgres:reindex', ['--scope' => [(string) $this->tenant->getKey()]])
        ->assertSuccessful();

    expect(app(SearchIndexStatistics::class)->total(CrossScope::platformWide('suite')))->toBe(1);
});

it('refuses to reindex a scoped corpus with no scope given, rather than doing every tenant', function (): void {
    $this->artisan('scout-postgres:reindex')->assertFailed();
});
