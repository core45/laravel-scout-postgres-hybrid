<?php

declare(strict_types=1);

use Core45\ScoutPostgres\Exceptions\InvalidScopeConfiguration;
use Core45\ScoutPostgres\Scope\ScopeDefinition;

/**
 * What a scope column or table name is allowed to be.
 *
 * The rule is not "anything PostgreSQL would accept". The published migration
 * quotes identifiers and the raw search and upsert SQL does not, so only a name
 * that means the same thing both ways can be configured: lowercase, and not a
 * reserved keyword. Everything below is a name that would otherwise create a
 * schema this package cannot query.
 */
it('rejects an all-uppercase column, which the quoted DDL and the unquoted queries would not agree about', function (): void {
    ScopeDefinition::fromConfig(['mode' => 'column', 'column' => 'TENANT_ID']);
})->throws(InvalidScopeConfiguration::class);

it('rejects a mixed-case column rather than silently lowercasing it', function (): void {
    ScopeDefinition::fromConfig(['mode' => 'column', 'column' => 'Tenant_ID']);
})->throws(InvalidScopeConfiguration::class);

it('rejects a mixed-case foreign table for the same reason as the column', function (): void {
    ScopeDefinition::column('tenant_id')->constrainedTo('Tenants');
})->throws(InvalidScopeConfiguration::class);

it('rejects a reserved keyword as a column, since it is interpolated unquoted into raw SQL', function (): void {
    ScopeDefinition::fromConfig(['mode' => 'column', 'column' => 'select']);
})->throws(InvalidScopeConfiguration::class);

it('rejects a reserved keyword as a foreign table', function (): void {
    ScopeDefinition::column('tenant_id')->constrainedTo('user');
})->throws(InvalidScopeConfiguration::class);

it('accepts a lowercase snake_case column and table', function (): void {
    $definition = ScopeDefinition::fromConfig([
        'mode' => 'column',
        'column' => 'tenant_id',
        'table' => 'tenants',
        'foreign_key' => true,
    ]);

    expect($definition->column)->toBe('tenant_id')
        ->and($definition->foreignTable)->toBe('tenants');
});

it('accepts a leading underscore and digits, which fold to themselves unquoted', function (): void {
    expect(ScopeDefinition::column('_scope_2')->column)->toBe('_scope_2');
});

it('still rejects a statement terminator in the column, the payload the pattern always refused', function (): void {
    ScopeDefinition::fromConfig(['mode' => 'column', 'column' => 'shop_id; drop table users']);
})->throws(InvalidScopeConfiguration::class);

it('still rejects whitespace inside the column', function (): void {
    ScopeDefinition::fromConfig(['mode' => 'column', 'column' => 'shop id']);
})->throws(InvalidScopeConfiguration::class);

it('still rejects a statement terminator in the foreign table', function (): void {
    ScopeDefinition::column('shop_id')->constrainedTo('shops; drop table users');
})->throws(InvalidScopeConfiguration::class);

it('names the snake_case form to use in the message, so the fix is in the failure', function (): void {
    expect(fn (): ScopeDefinition => ScopeDefinition::column('Tenant_ID'))
        ->toThrow(InvalidScopeConfiguration::class, 'snake_case');
});
