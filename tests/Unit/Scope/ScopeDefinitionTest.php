<?php

declare(strict_types=1);

use Core45\ScoutPostgres\Exceptions\InvalidScopeConfiguration;
use Core45\ScoutPostgres\Exceptions\UnresolvableScope;
use Core45\ScoutPostgres\Scope\ScopeDefinition;

it('hydrates mode none from config as a fully unscoped definition', function (): void {
    $definition = ScopeDefinition::fromConfig(['mode' => 'none']);

    expect($definition->isScoped())->toBeFalse()
        ->and($definition->hasForeignKey())->toBeFalse()
        ->and($definition->column)->toBeNull();
});

it('hydrates the shipped default config without throwing, single-tenant being a configured state not absent configuration', function (): void {
    $scope = require __DIR__.'/../../../config/scout-postgres.php';
    $scope = $scope['scope'];

    // The shipped mode is 'none' unless SCOUT_POSTGRES_SCOPE_MODE overrides it in this
    // environment; either way the populated siblings (column, table, foreign_key,
    // on_delete) must be inert rather than contradictory, so hydration never throws
    // and isScoped() agrees with whatever mode the array actually contains.
    $definition = ScopeDefinition::fromConfig($scope);

    expect($definition->mode)->toBe(strtolower(trim((string) $scope['mode'])))
        ->and($definition->isScoped())->toBe($definition->mode === ScopeDefinition::MODE_COLUMN);
});

it('hydrates a full column-mode config with a foreign key', function (): void {
    $definition = ScopeDefinition::fromConfig([
        'mode' => 'column',
        'column' => 'shop_id',
        'table' => 'shops',
        'foreign_key' => true,
        'on_delete' => 'cascade',
        'nullable' => false,
    ]);

    expect($definition->isScoped())->toBeTrue()
        ->and($definition->column)->toBe('shop_id')
        ->and($definition->foreignTable)->toBe('shops')
        ->and($definition->onDeleteAction)->toBe('cascade')
        ->and($definition->hasForeignKey())->toBeTrue();
});

it('hydrates column mode with foreign_key false and no table needed', function (): void {
    $definition = ScopeDefinition::fromConfig([
        'mode' => 'column',
        'column' => 'shop_id',
        'foreign_key' => false,
    ]);

    expect($definition->hasForeignKey())->toBeFalse()
        ->and($definition->foreignTable)->toBeNull();
});

it('throws when mode is missing rather than defaulting to none', function (): void {
    ScopeDefinition::fromConfig([]);
})->throws(InvalidScopeConfiguration::class);

it('throws when the mode is unrecognised rather than falling back to none', function (): void {
    ScopeDefinition::fromConfig(['mode' => 'colum']);
})->throws(InvalidScopeConfiguration::class);

it('throws when the mode is not a string rather than coercing it', function (): void {
    ScopeDefinition::fromConfig(['mode' => true]);
})->throws(InvalidScopeConfiguration::class);

it('throws when column mode has no column key', function (): void {
    ScopeDefinition::fromConfig(['mode' => 'column']);
})->throws(InvalidScopeConfiguration::class);

it('throws when column mode has an empty column value', function (): void {
    ScopeDefinition::fromConfig(['mode' => 'column', 'column' => '']);
})->throws(InvalidScopeConfiguration::class);

it('throws when foreign_key is true with no table named', function (): void {
    ScopeDefinition::fromConfig([
        'mode' => 'column',
        'column' => 'shop_id',
        'foreign_key' => true,
    ]);
})->throws(InvalidScopeConfiguration::class);

it('throws when the column is not a valid unquoted identifier, since it is interpolated into DDL', function (): void {
    ScopeDefinition::fromConfig([
        'mode' => 'column',
        'column' => 'shop_id; drop table users',
    ]);
})->throws(InvalidScopeConfiguration::class);

it('throws when on_delete is not one of the recognised referential actions', function (): void {
    ScopeDefinition::fromConfig([
        'mode' => 'column',
        'column' => 'shop_id',
        'foreign_key' => true,
        'table' => 'shops',
        'on_delete' => 'explode',
    ]);
})->throws(InvalidScopeConfiguration::class);

it('throws when nullable is not a boolean rather than coercing a truthy string', function (): void {
    ScopeDefinition::fromConfig([
        'mode' => 'column',
        'column' => 'shop_id',
        'nullable' => 'yes',
    ]);
})->throws(InvalidScopeConfiguration::class);

it('normalises the mode by trimming whitespace and ignoring case', function (): void {
    $definition = ScopeDefinition::fromConfig(['mode' => ' Column ', 'column' => 'shop_id']);

    expect($definition->isScoped())->toBeTrue()
        ->and($definition->mode)->toBe(ScopeDefinition::MODE_COLUMN);
});

it('throws when on_delete is set null but the column is not nullable', function (): void {
    ScopeDefinition::fromConfig([
        'mode' => 'column',
        'column' => 'shop_id',
        'foreign_key' => true,
        'table' => 'shops',
        'on_delete' => 'set null',
        'nullable' => false,
    ]);
})->throws(InvalidScopeConfiguration::class);

it('accepts on_delete set null when the column is nullable', function (): void {
    $definition = ScopeDefinition::fromConfig([
        'mode' => 'column',
        'column' => 'shop_id',
        'foreign_key' => true,
        'table' => 'shops',
        'on_delete' => 'set null',
        'nullable' => true,
    ]);

    expect($definition->onDeleteAction)->toBe('set null')
        ->and($definition->nullable)->toBeTrue();
});

it('builds the same definition through chained named constructors as through fromConfig', function (): void {
    $intermediate = ScopeDefinition::column('shop_id');
    $definition = $intermediate->constrainedTo('shops')->onDelete('cascade');

    expect($definition->isScoped())->toBeTrue()
        ->and($definition->column)->toBe('shop_id')
        ->and($definition->foreignTable)->toBe('shops')
        ->and($definition->onDeleteAction)->toBe('cascade')
        ->and($definition->hasForeignKey())->toBeTrue();

    expect($intermediate->foreignTable)->toBeNull();
});

it('lets requireColumn return the column in column mode', function (): void {
    $definition = ScopeDefinition::column('shop_id');

    expect($definition->requireColumn())->toBe('shop_id');
});

it('makes requireColumn throw in none mode, so a forgotten isScoped check cannot produce an unfiltered query', function (): void {
    ScopeDefinition::none()->requireColumn();
})->throws(UnresolvableScope::class);

it('makes constrainedTo throw on an unscoped definition', function (): void {
    ScopeDefinition::none()->constrainedTo('shops');
})->throws(UnresolvableScope::class);

it('makes onDelete throw on an unscoped definition', function (): void {
    ScopeDefinition::none()->onDelete('cascade');
})->throws(UnresolvableScope::class);

it('makes nullable throw on an unscoped definition', function (): void {
    ScopeDefinition::none()->nullable();
})->throws(UnresolvableScope::class);

it('builds a colon-separated cache segment in column mode', function (): void {
    expect(ScopeDefinition::column('shop_id')->cacheSegment(7))->toBe('7:');
});

it('builds an empty cache segment in none mode so single-tenant never gets a dangling separator', function (): void {
    expect(ScopeDefinition::none()->cacheSegment(7))->toBe('');
});
