<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Contracts;

use Core45\ScoutPostgres\Exceptions\UnknownDocumentType;
use Illuminate\Database\Eloquent\Model;

/**
 * The adopter's set of indexable types.
 *
 * The host application models types as an enum, which answers three questions
 * for free: which type is this model, which type is this stored string, and what
 * are all the types. An interface answers none of them — `DocumentType` has no
 * `::cases()` and no `::from()` — so the questions have to live somewhere, and
 * this is that somewhere.
 *
 * It exists because the engine genuinely needs all three. Scout hands the engine
 * a model and expects it to find the corpus (`forModel`); a row read back from
 * `search_documents` carries only the stored string (`fromValue`); and a
 * platform-wide count has to enumerate the corpus (`all`).
 *
 * Resolution is by `DocumentType::value()`, never by object identity: an adopter
 * whose types are an enum gets the same instance every time, but one whose types
 * are ordinary objects does not, and code that compared with `===` would work in
 * testing and fail in production.
 */
interface DocumentTypeRegistry
{
    /**
     * The type this model is indexed as.
     *
     * @throws UnknownDocumentType when no registered type claims the model
     */
    public function forModel(Model $model): DocumentType;

    /**
     * The type behind a stored `searchable_type` value.
     *
     * @throws UnknownDocumentType when no registered type has that value
     */
    public function fromValue(string $value): DocumentType;

    /**
     * Every registered type.
     *
     * This is what replaces `SearchableType::cases()` at the call sites that
     * legitimately need the whole set: platform-wide statistics, orphan
     * reconciliation, and a search with no explicit type list.
     *
     * @return list<DocumentType>
     */
    public function all(): array;
}
