<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Tests\Fixtures\Models;

use Core45\ScoutPostgres\Concerns\SearchableWithoutSyncing;
use Core45\ScoutPostgres\Contracts\SearchIndexable;
use Core45\ScoutPostgres\DTOs\SearchDocumentData;
use Core45\ScoutPostgres\Tests\Fixtures\ArticleType;
use Illuminate\Database\Eloquent\Model;

/**
 * The one indexable fixture model.
 *
 * Carries `tenant_id` on its own table on purpose: C3 makes that a requirement
 * for adopters in `column` mode, because hydration filters the source table as
 * well as the document table. A fixture without it would let a C3 regression
 * pass unnoticed.
 */
class Article extends Model implements SearchIndexable
{
    // Scout's query side without its observer, which is how an adopter reaches
    // Article::search(). The write path is this package's own observer.
    use SearchableWithoutSyncing;

    /**
     * @var list<string>
     */
    protected $fillable = ['tenant_id', 'title', 'body', 'locale', 'published'];

    /**
     * @var array<string, string>
     */
    protected $casts = ['published' => 'boolean'];

    /**
     * @return list<SearchDocumentData>
     */
    public function toSearchDocuments(): array
    {
        // An unpublished article returns no documents rather than implementing a
        // separate predicate, so unpublishing and deleting converge on the same
        // state — which is the contract's whole point.
        if ($this->published === false) {
            return [];
        }

        return [
            SearchDocumentData::make(
                searchableType: ArticleType::Article,
                searchableId: (int) $this->getKey(),
                locale: (string) $this->locale,
                title: (string) $this->title,
                body: (string) ($this->body ?? ''),
                filters: ['published' => (bool) $this->published],
            ),
        ];
    }
}
