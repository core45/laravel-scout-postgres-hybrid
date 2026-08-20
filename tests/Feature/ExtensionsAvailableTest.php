<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * The P0 gate.
 *
 * This test exists to prove the CI service container is the right image before a
 * single line of engine code is written. `pgvector/pgvector:pg17` ships the vector
 * extension; stock `postgres:17` does not, and `CREATE EXTENSION IF NOT EXISTS
 * vector` does not paper over that — PostgreSQL answers "extension \"vector\" is
 * not available" and the migration fails.
 *
 * If this test is green, the pipeline can build the schema. If it is red, nothing
 * downstream is worth debugging.
 */
it('runs against postgresql', function (): void {
    expect(DB::getDriverName())->toBe('pgsql');
});

it('has the three extensions the engine is built on', function (string $extension): void {
    $installed = DB::selectOne(
        'SELECT extname FROM pg_extension WHERE extname = ?',
        [$extension],
    );

    expect($installed)->not->toBeNull(
        "The `{$extension}` extension is not installed. On CI this means the service "
        .'image is not `pgvector/pgvector:pg17`.',
    );
})->with(['vector', 'pg_trgm', 'unaccent']);

it('can declare a vector column and measure distance', function (): void {
    DB::statement('CREATE TABLE scout_postgres_probe (id int primary key, embedding vector(3))');

    try {
        DB::statement("INSERT INTO scout_postgres_probe VALUES (1, '[1,0,0]'), (2, '[0,1,0]')");

        $nearest = DB::selectOne(
            "SELECT id, (embedding <=> '[1,0,0]'::vector) AS distance
               FROM scout_postgres_probe
              ORDER BY embedding <=> '[1,0,0]'::vector
              LIMIT 1",
        );

        expect((int) $nearest->id)->toBe(1)
            ->and((float) $nearest->distance)->toBeLessThan(0.0001);
    } finally {
        DB::statement('DROP TABLE IF EXISTS scout_postgres_probe');
    }
});

it('can fold diacritics and match trigrams', function (): void {
    $folded = DB::selectOne("SELECT unaccent('zdrowié') AS folded");
    expect($folded->folded)->toBe('zdrowie');

    $similar = DB::selectOne("SELECT similarity('joga', 'jogi') AS score");
    expect((float) $similar->score)->toBeGreaterThan(0.0);
});
