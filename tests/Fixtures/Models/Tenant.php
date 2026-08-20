<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Stands in for the host application's tenant model.
 *
 * Deliberately minimal, and deliberately carries no global scope. The package
 * must work against a tenant type it knows nothing about, so a fixture that
 * offered conveniences the real thing does not would prove the wrong thing. Its
 * only job is to give the scope column something to reference.
 */
class Tenant extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = ['name'];
}
