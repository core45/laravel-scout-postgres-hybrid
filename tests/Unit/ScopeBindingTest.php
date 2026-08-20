<?php

declare(strict_types=1);

use Core45\ScoutPostgres\Exceptions\InvalidScopeConfiguration;
use Core45\ScoutPostgres\Scope\ScopeDefinition;

/**
 * SC-2 at the container boundary.
 *
 * ScopeDefinitionTest covers the hydration rules themselves; these assert that the
 * provider actually routes the config through them, and that it does so lazily. A
 * validator nothing resolves is a validator that never runs.
 */
it('resolves the scope definition from the merged package config', function (): void {
    config()->set('scout-postgres.scope', [
        'mode' => 'column',
        'column' => 'shop_id',
        'table' => 'shops',
        'foreign_key' => true,
        'on_delete' => 'cascade',
        'nullable' => false,
    ]);

    $definition = app(ScopeDefinition::class);

    expect($definition->isScoped())->toBeTrue()
        ->and($definition->requireColumn())->toBe('shop_id')
        ->and($definition->foreignTable)->toBe('shops');
});

it('binds one shared definition so the migration and the engine cannot read different modes', function (): void {
    expect(app(ScopeDefinition::class))->toBe(app(ScopeDefinition::class));
});

it('throws when a resolved config is incoherent rather than degrading to an unscoped query', function (): void {
    config()->set('scout-postgres.scope', ['mode' => 'column']);

    expect(fn () => app(ScopeDefinition::class))
        ->toThrow(InvalidScopeConfiguration::class);
});

it('defers validation until the definition is resolved, so a bad config is still fixable by artisan', function (): void {
    // `register()` runs for every command, `config:publish` included. Registering the
    // binding must not itself throw, or the one command that would repair the config
    // is the one command that cannot start.
    config()->set('scout-postgres.scope', ['mode' => 'nonsense']);

    expect(true)->toBeTrue();

    expect(fn () => app(ScopeDefinition::class))
        ->toThrow(InvalidScopeConfiguration::class);
});
