<?php

declare(strict_types=1);

use Core45\ScoutPostgres\Scope\CrossScope;

/**
 * CrossScope exists at all because SC-3 requires platform-wide reads to go through
 * a named, architecture-testable path rather than a boolean flag. A flag on an
 * existing method is reachable by accident — `true` landing in the wrong argument
 * position silently widens a query — whereas constructing this type is deliberate
 * and greppable, and its private constructor plus final/readonly shape is exactly
 * what an architecture test can assert on to enforce that no other path exists.
 */
it('exposes the reason a platform-wide read was constructed for', function (): void {
    $crossScope = CrossScope::platformWide('counting documents across every tenant');

    expect($crossScope->reason)->toBe('counting documents across every tenant');
});

it('has a private constructor so the type cannot be built by accident', function (): void {
    $constructor = (new ReflectionClass(CrossScope::class))->getConstructor();

    expect($constructor)->not->toBeNull()
        ->and($constructor->isPrivate())->toBeTrue();
});

it('is final and readonly so the named path cannot be subclassed or mutated around', function (): void {
    $reflection = new ReflectionClass(CrossScope::class);

    expect($reflection->isFinal())->toBeTrue()
        ->and($reflection->isReadOnly())->toBeTrue();
});
