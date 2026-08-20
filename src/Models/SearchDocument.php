<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Models;

use Core45\ScoutPostgres\Casts\Vector;
use Core45\ScoutPostgres\Scope\CrossScope;
use Core45\ScoutPostgres\Scope\ScopeDefinition;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One denormalised search row per (source model, locale).
 *
 * Where a scope column is configured, every tenant shares this one table, and
 * that column is the isolation. That is worth stating plainly because it is a
 * downgrade from the usual per-tenant-index arrangement: vector similarity needs
 * no matching id or keyword to surface a neighbour's document, so with separate
 * corpora the separation itself was the defence. Here the predicate is.
 *
 * The package deliberately ships **no** global scope on this model. A scope that
 * throws with no bound tenant is inert on exactly the paths that matter — queue
 * workers and console commands are unauthenticated — so relying on one would be
 * relying on a guard that is absent when it counts. Isolation is instead pinned
 * explicitly by the search service and the indexer, which repeat the scope
 * predicate inside every raw CTE branch.
 *
 * Do not query this model directly. All reads go through the search service,
 * which is what applies the scope. The one legitimate exception is a
 * platform-wide read, which must be requested through
 * {@see CrossScope}.
 *
 * Columns maintained by PostgreSQL rather than by PHP:
 *  - `search_vector` — a weighted tsvector built by the
 *    `search_documents_vector_update` trigger from `title`, `body` and
 *    `text_search_config`. Never assign it; it is not fillable and is hidden.
 */
class SearchDocument extends Model
{
    /**
     * @var list<string>
     */
    protected $hidden = ['search_vector'];

    /**
     * The scope column is fillable only when one is configured, so a
     * single-tenant install cannot mass-assign a column its table does not have.
     *
     * @var list<string>
     */
    protected $fillable = [
        'searchable_type',
        'searchable_id',
        'locale',
        'text_search_config',
        'title',
        'body',
        'trigram_text',
        'content_hash',
        'filters',
        'embedding',
        'embedding_fingerprint',
    ];

    /**
     * @return list<string>
     */
    public function getFillable(): array
    {
        $scope = $this->scopeDefinition();

        return $scope->isScoped()
            ? [...$this->fillable, $scope->requireColumn()]
            : $this->fillable;
    }

    /**
     * `searchable_type` is cast to a plain string, not to a type object.
     *
     * The host application casts it to its `SearchableType` enum. The package
     * cannot: `DocumentType` is an interface the adopter implements, and Eloquent
     * casts need a concrete class able to reconstruct itself from a column value.
     * Resolution back to a `DocumentType` therefore happens where the adopter's
     * type registry is available, not here.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'searchable_id' => 'integer',
            'filters' => 'array',
            'embedding' => Vector::class,
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Not a real Laravel morph: `searchable_type` stores a `DocumentType` value
     * rather than a class name or a morph alias, so the class is resolved through
     * the adopter's type registry instead of the morph map.
     *
     * @return MorphTo<Model, $this>
     */
    public function searchable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Whether the resolved text still matches what was indexed.
     *
     * The indexer compares this before doing any work: an unchanged hash means
     * neither the tsvector nor the embedding needs regenerating, which is what
     * keeps per-locale fan-out from costing one embedding call per locale when a
     * model has a single translation.
     */
    public function matchesContent(string $title, string $body): bool
    {
        return $this->getAttribute('content_hash') === self::hashContent($title, $body);
    }

    public static function hashContent(string $title, string $body): string
    {
        return hash('sha256', $title."\0".$body);
    }

    private function scopeDefinition(): ScopeDefinition
    {
        return app(ScopeDefinition::class);
    }
}
