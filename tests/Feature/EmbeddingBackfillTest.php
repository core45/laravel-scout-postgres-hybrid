<?php

declare(strict_types=1);

use Core45\ScoutPostgres\Contracts\EmbeddingProvider;
use Core45\ScoutPostgres\Embedding\EmbeddingBackfill;
use Core45\ScoutPostgres\Search\SearchIndexer;
use Core45\ScoutPostgres\Tests\Fixtures\FakeEmbeddings;
use Core45\ScoutPostgres\Tests\Fixtures\FixtureScope;
use Core45\ScoutPostgres\Tests\Fixtures\Models\Article;
use Core45\ScoutPostgres\Tests\Fixtures\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * The window between reading a row and writing its vector.
 *
 * `pending()` selects under the scope predicate, then the provider call is a
 * network round-trip during which the row can be edited or reassigned. The
 * update therefore repeats the state that was read; these tests drive that
 * window directly by mutating the row from inside the provider, which is the
 * only moment the race can occur.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::query()->create(['name' => 'Acme']);
    FixtureScope::bind((int) $this->tenant->getKey());
});

/**
 * A provider that changes the row it is being asked to embed.
 *
 * The mutation runs where the real race lives — after the select, before the
 * update — so the test needs no hook in the backfill itself. It still returns a
 * genuine vector, because a null one would make `run()` skip `store()` entirely
 * and the assertions below would pass without the WHERE clause ever being tried.
 */
function racingEmbeddings(Closure $mutate): EmbeddingProvider
{
    return new class($mutate) implements EmbeddingProvider
    {
        public function __construct(private readonly Closure $mutate) {}

        /**
         * @return ?list<float>
         */
        public function embed(string $text): ?array
        {
            ($this->mutate)();

            return (new FakeEmbeddings)->embed($text);
        }

        public function isReady(): bool
        {
            return true;
        }

        public function fingerprint(): string
        {
            return 'fake:v1';
        }
    };
}

function indexedArticle(Tenant $tenant): int
{
    $article = Article::query()->create([
        'tenant_id' => $tenant->getKey(),
        'title' => 'Yoga mat',
        'body' => 'A thick mat.',
        'locale' => 'en',
        'published' => true,
    ]);

    app(SearchIndexer::class)->reconcileModel($article);

    return (int) DB::table('search_documents')
        ->where('searchable_type', 'article')
        ->where('searchable_id', (int) $article->getKey())
        ->value('id');
}

it('fills a null vector left behind by the indexer', function (): void {
    app()->instance(EmbeddingProvider::class, new FakeEmbeddings);

    $documentId = indexedArticle($this->tenant);

    $embedded = app(EmbeddingBackfill::class)->run((int) $this->tenant->getKey());

    $row = DB::selectOne('SELECT embedding, embedding_fingerprint FROM search_documents WHERE id = ?', [$documentId]);

    expect($embedded)->toBe(1)
        ->and($row->embedding)->not->toBeNull()
        ->and($row->embedding_fingerprint)->toBe('fake:v1');
});

it('does not write a vector onto a row whose content changed while the provider was called', function (): void {
    $documentId = indexedArticle($this->tenant);

    app()->instance(EmbeddingProvider::class, racingEmbeddings(function () use ($documentId): void {
        DB::update('UPDATE search_documents SET content_hash = ? WHERE id = ?', [str_repeat('f', 64), $documentId]);
    }));

    $embedded = app(EmbeddingBackfill::class)->run((int) $this->tenant->getKey());

    $row = DB::selectOne('SELECT embedding, embedding_fingerprint FROM search_documents WHERE id = ?', [$documentId]);

    // The vector describes text the row no longer holds. Writing it would leave a
    // row ranked against its own history, and the tally would claim a row that was
    // never correctly embedded.
    expect($embedded)->toBe(0)
        ->and($row->embedding)->toBeNull()
        ->and($row->embedding_fingerprint)->toBeNull();

    // And the row is not lost: the next run reads the current text and embeds it.
    app()->instance(EmbeddingProvider::class, new FakeEmbeddings);

    $embedded = app(EmbeddingBackfill::class)->run((int) $this->tenant->getKey());

    $row = DB::selectOne('SELECT embedding, embedding_fingerprint FROM search_documents WHERE id = ?', [$documentId]);

    expect($embedded)->toBe(1)
        ->and($row->embedding)->not->toBeNull()
        ->and($row->embedding_fingerprint)->toBe('fake:v1');
});

it('does not write a vector onto a row that was reassigned to another scope mid-flight', function (): void {
    $other = Tenant::query()->create(['name' => 'Other']);
    $documentId = indexedArticle($this->tenant);

    app()->instance(EmbeddingProvider::class, racingEmbeddings(function () use ($documentId, $other): void {
        DB::update('UPDATE search_documents SET tenant_id = ? WHERE id = ?', [(int) $other->getKey(), $documentId]);
    }));

    $embedded = app(EmbeddingBackfill::class)->run((int) $this->tenant->getKey());

    $row = DB::selectOne('SELECT embedding, embedding_fingerprint FROM search_documents WHERE id = ?', [$documentId]);

    // This is the one that matters: the id survived the move, so an unscoped update
    // would have written one tenant's vector onto another tenant's current row.
    expect($embedded)->toBe(0)
        ->and($row->embedding)->toBeNull()
        ->and($row->embedding_fingerprint)->toBeNull();
});
