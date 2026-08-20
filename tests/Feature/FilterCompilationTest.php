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
use Illuminate\Support\Facades\DB;

/**
 * The three filter shapes, which nothing else in the suite exercised.
 *
 * `SearchQuery` documents containment, ORed containment and numeric ranges as
 * the whole of C6 — every predicate inside the search rather than applied to its
 * results — and until this file existed all three were configured and none were
 * proven.
 *
 * The range case is the one with teeth. `filters` is arbitrary JSON, so nothing
 * stops one document storing `price: "POA"` while the rest store numbers, and an
 * unguarded `(filters->>'price')::numeric` does not skip that row — it aborts the
 * entire statement with *"invalid input syntax for type numeric"*. One bad
 * document takes the search page down for every query that mentions a range.
 */
beforeEach(function (): void {
    app()->instance(EmbeddingProvider::class, new FakeEmbeddings);

    $this->tenant = Tenant::query()->create(['name' => 'Acme']);
    FixtureScope::bind((int) $this->tenant->getKey());
});

/**
 * Index an article, then overwrite its `filters` payload directly.
 *
 * `Article::toSearchDocuments()` hardcodes `['published' => true]` — deliberately,
 * since it is a fixture for the write path — so the only way to give a document
 * the heterogeneous payload this file is about is to write the column after the
 * indexer has run. That is exactly the state an adopter's own
 * `toSearchDocuments()` would produce; the shortcut is in how it is reached, not
 * in what is stored.
 *
 * @param  array<string, mixed>  $filters
 */
function indexWithFilters(string $title, array $filters, Tenant $tenant): Article
{
    $article = Article::query()->create([
        'tenant_id' => $tenant->getKey(),
        'title' => $title,
        'body' => 'A thick mat suitable for yoga and stretching.',
        'locale' => 'en',
        'published' => true,
    ]);

    app(SearchIndexer::class)->reconcileModel($article);

    DB::table('search_documents')
        ->where('searchable_type', ArticleType::Article->value())
        ->where('searchable_id', (int) $article->getKey())
        ->update(['filters' => json_encode($filters, JSON_THROW_ON_ERROR)]);

    return $article;
}

/**
 * @param  array<string, mixed>  $filters
 * @param  array<string, list<scalar>>  $anyFilters
 * @param  array<string, array{min?: int|float, max?: int|float}>  $rangeFilters
 */
function filteredQuery(array $filters = [], array $anyFilters = [], array $rangeFilters = []): SearchQuery
{
    return SearchQuery::make(
        term: 'yoga',
        locale: 'en',
        types: [ArticleType::Article],
        filters: $filters,
        anyFilters: $anyFilters,
        rangeFilters: $rangeFilters,
    );
}

it('excludes a non-numeric value from a range filter instead of aborting the search', function (): void {
    $numeric = indexWithFilters('Yoga mat for exercises', ['published' => true, 'price' => 20], $this->tenant);
    $poa = indexWithFilters('Yoga mat on request', ['published' => true, 'price' => 'POA'], $this->tenant);

    $results = PostgresSearchService::for((int) $this->tenant->getKey())
        ->keyword(filteredQuery(rangeFilters: ['price' => ['min' => 10]]));

    $ids = array_map(fn ($hit): int => $hit->searchableId, $results->hits);

    // Not merely "the POA row is absent" — reaching this assertion at all is the
    // result, because the unguarded cast raised a QueryException before any rows
    // came back.
    expect($ids)->toContain((int) $numeric->getKey())
        ->and($ids)->not->toContain((int) $poa->getKey());
});

it('applies both bounds of a range, and still tolerates the non-numeric neighbour', function (): void {
    $cheap = indexWithFilters('Yoga mat cheap', ['published' => true, 'price' => 20], $this->tenant);
    $dear = indexWithFilters('Yoga mat dear', ['published' => true, 'price' => 500], $this->tenant);
    indexWithFilters('Yoga mat on request', ['published' => true, 'price' => 'POA'], $this->tenant);

    $results = PostgresSearchService::for((int) $this->tenant->getKey())
        ->keyword(filteredQuery(rangeFilters: ['price' => ['min' => 10, 'max' => 100]]));

    $ids = array_map(fn ($hit): int => $hit->searchableId, $results->hits);

    // Both bounds bind the key twice each — four bindings for one key in one
    // branch — so a drifted binding shows up here as the wrong row surviving.
    expect($ids)->toBe([(int) $cheap->getKey()])
        ->and($ids)->not->toContain((int) $dear->getKey());
});

it('keeps a decimal value inside its range rather than rejecting it with the guard', function (): void {
    $article = indexWithFilters('Yoga mat for exercises', ['published' => true, 'price' => 19.99], $this->tenant);

    $results = PostgresSearchService::for((int) $this->tenant->getKey())
        ->keyword(filteredQuery(rangeFilters: ['price' => ['min' => 10, 'max' => 20]]));

    $ids = array_map(fn ($hit): int => $hit->searchableId, $results->hits);

    // The guard is a regex, so anything it fails to describe becomes a silently
    // missing row rather than an error — a stricter failure than the crash it
    // replaced, and the reason the pattern covers decimals and exponents.
    expect($ids)->toBe([(int) $article->getKey()]);
});

it('narrows a search by jsonb containment', function (): void {
    $draft = indexWithFilters('Yoga mat draft', ['published' => false], $this->tenant);
    $live = indexWithFilters('Yoga mat live', ['published' => true], $this->tenant);

    $results = PostgresSearchService::for((int) $this->tenant->getKey())
        ->keyword(filteredQuery(filters: ['published' => true]));

    $ids = array_map(fn ($hit): int => $hit->searchableId, $results->hits);

    expect($ids)->toBe([(int) $live->getKey()])
        ->and($ids)->not->toContain((int) $draft->getKey());
});

it('accepts any of several values for one key', function (): void {
    $public = indexWithFilters('Yoga mat public', ['audience' => 'public'], $this->tenant);
    $partner = indexWithFilters('Yoga mat partner', ['audience' => 'partner'], $this->tenant);
    $internal = indexWithFilters('Yoga mat internal', ['audience' => 'internal'], $this->tenant);

    $results = PostgresSearchService::for((int) $this->tenant->getKey())
        ->keyword(filteredQuery(anyFilters: ['audience' => ['public', 'partner']]));

    $ids = array_map(fn ($hit): int => $hit->searchableId, $results->hits);

    // Containment cannot express IN, so this compiles to ORed single-key
    // containments. A version that ANDed them would return nothing at all.
    expect($ids)->toContain((int) $public->getKey())
        ->and($ids)->toContain((int) $partner->getKey())
        ->and($ids)->not->toContain((int) $internal->getKey());
});

it('compiles the same filters for the pure semantic path', function (): void {
    $numeric = indexWithFilters('Yoga mat for exercises', ['audience' => 'public', 'price' => 20], $this->tenant);
    $poa = indexWithFilters('Yoga mat on request', ['audience' => 'public', 'price' => 'POA'], $this->tenant);

    app(EmbeddingBackfill::class)->run((int) $this->tenant->getKey());

    $results = PostgresSearchService::for((int) $this->tenant->getKey())
        ->semantic(SearchQuery::make(
            term: 'Yoga mat for exercises',
            locale: 'en',
            types: [ArticleType::Article],
            anyFilters: ['audience' => ['public']],
            rangeFilters: ['price' => ['min' => 10]],
        ));

    $ids = array_map(fn ($hit): int => $hit->searchableId, $results->hits);

    // SemanticSearchService builds its filters through the query builder rather
    // than the raw fragments PostgresSearchService assembles, so it is a second
    // implementation of the same contract and needs its own coverage.
    expect($ids)->toContain((int) $numeric->getKey())
        ->and($ids)->not->toContain((int) $poa->getKey());
});
