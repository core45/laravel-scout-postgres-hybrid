<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Tests\Fixtures;

use Core45\ScoutPostgres\Contracts\DocumentType;
use Core45\ScoutPostgres\Tests\Fixtures\Models\Article;

/**
 * The fixture's document type, as a backed enum.
 *
 * An enum on purpose: it is the shape the interface was extracted from and the
 * one most adopters will reach for, so the registry's enum branch is exercised
 * by the suite rather than only by adopters.
 */
enum ArticleType: string implements DocumentType
{
    case Article = 'article';

    public function value(): string
    {
        return $this->value;
    }

    public function modelClass(): string
    {
        return Article::class;
    }

    public function isTranslatable(): bool
    {
        return true;
    }
}
