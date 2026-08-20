<?php

declare(strict_types=1);

use Core45\ScoutPostgres\Exceptions\UnresolvableScope;
use Core45\ScoutPostgres\Search\SearchIndexer;
use Core45\ScoutPostgres\Tests\Fixtures\FixtureScope;
use Core45\ScoutPostgres\Tests\Fixtures\Models\Article;
use Core45\ScoutPostgres\Tests\Fixtures\Models\Tenant;

/**
 * The tests that should have existed before v1.0.0.
 *
 * An adversarial review found that a malformed scope string was cast straight to
 * int, so `'1-not-authorized'` selected tenant 1 and returned its documents. The
 * suite missed it because every test passed a valid integer. These assert the
 * malformed shapes specifically.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Acme']);
    FixtureScope::bind((int) $this->tenant->getKey());

    $this->article = Article::query()->create([
        'tenant_id' => $this->tenant->getKey(),
        'title' => 'Yoga mat',
        'body' => 'Body.',
        'locale' => 'en',
        'published' => true,
    ]);

    app(SearchIndexer::class)->reconcileModel($this->article);
});

it('refuses a scope option that only looks numeric, instead of casting it to a real tenant', function (): void {
    // `(int) '1-not-authorized'` is 1 in PHP. Casting it made an unvalidated
    // request value select a genuine tenant's documents.
    expect(fn () => Article::search('yoga')->options(['scope' => '1-not-authorized'])->get())
        ->toThrow(UnresolvableScope::class);
});

it('refuses a wholly non-numeric scope option rather than silently using zero', function (): void {
    expect(fn () => Article::search('yoga')->options(['scope' => 'acme'])->get())
        ->toThrow(UnresolvableScope::class);
});

it('still accepts a genuinely numeric scope option', function (): void {
    $results = Article::search('yoga')
        ->options(['scope' => (string) $this->tenant->getKey()])
        ->get();

    expect($results)->toHaveCount(1);
});

it('refuses to reconcile a model whose scope column was never loaded', function (): void {
    // A partial select() leaves no scope attribute. This used to be treated as
    // "no scope" and skipped, so deleting such a model left its document in the
    // index for ever.
    $partial = Article::query()->select('id')->findOrFail($this->article->getKey());

    expect(fn () => app(SearchIndexer::class)->reconcileModel($partial))
        ->toThrow(UnresolvableScope::class);
});

it('still skips a model whose scope is genuinely empty', function (): void {
    $unassigned = Article::query()->create([
        'tenant_id' => null,
        'title' => 'Orphan',
        'body' => 'Body.',
        'locale' => 'en',
        'published' => true,
    ]);

    // Absent and empty are different: this one was never assigned to a tenant, so
    // skipping is right where throwing would not be.
    app(SearchIndexer::class)->reconcileModel($unassigned);
})->throwsNoExceptions();

it('rejects a malformed --scope rather than reindexing the tenant it casts to', function (): void {
    $this->artisan('scout-postgres:reindex', ['--scope' => ['1garbage']])->assertFailed();
});
