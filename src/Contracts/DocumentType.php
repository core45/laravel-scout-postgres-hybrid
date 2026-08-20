<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * One indexable kind of thing, as the package sees it.
 *
 * The host application models this as a `SearchableType` enum with seven cases, which
 * leaks into all five DTOs, the text-search config, the service provider and the
 * document cast. A package cannot ship that enum: its cases are the adopter's domain.
 *
 * What survives extraction is the `searchable_type` column, already `varchar(64)`, and
 * this contract over it. A host enum can implement the interface directly, so adopting
 * the package costs an `implements` clause rather than a rewrite.
 *
 * `value()` is the stored discriminator and the join key for the polymorphic hydration
 * path, so it must be stable across deploys: renaming it orphans every indexed row of
 * that type.
 */
interface DocumentType
{
    /**
     * A locale that matches any query locale.
     *
     * Non-translatable content is indexed once under this value rather than duplicated
     * per locale, and the keyword, trigram and semantic branches all treat it as a
     * wildcard on the `locale` column.
     */
    public const LOCALE_ANY = '*';

    /**
     * The value stored in `search_documents.searchable_type`. Max 64 characters.
     *
     * @return non-empty-string
     */
    public function value(): string;

    /**
     * The Eloquent model hydrated for hits of this type.
     *
     * @return class-string<Model>
     */
    public function modelClass(): string;

    /**
     * Whether this type is indexed once per locale.
     *
     * False means one row per model under `LOCALE_ANY`; true means one row per locale
     * and a locale predicate on every branch.
     */
    public function isTranslatable(): bool;
}
