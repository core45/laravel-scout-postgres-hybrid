<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The one indexable fixture model.
 *
 * Carries `tenant_id` on its own table on purpose: C3 makes that a requirement
 * for adopters in `column` mode, because hydration filters the source table as
 * well as the document table. A fixture without it would let a C3 regression
 * pass unnoticed.
 */
class Article extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['tenant_id', 'title', 'body', 'locale'];
}
