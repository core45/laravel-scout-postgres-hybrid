<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Extensions backing the hybrid search stack.
     *
     * - `vector`   — pgvector: the embedding column and the `<=>` distance operator.
     * - `pg_trgm`  — trigram similarity. Not optional: PostgreSQL ships no text search
     *                configuration for several languages (there is no `polish` row in
     *                `pg_ts_config`, for one), so those documents are stemmed with
     *                `simple` and trigram matching is the only thing relating inflections.
     * - `unaccent` — diacritic folding, so `zdrowie`/`zdrowié` and `ą`/`a` normalise.
     *
     * No-op outside pgsql: the engine returns empty results on other drivers rather
     * than failing, so a host running its default test suite on SQLite still migrates.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::EXTENSIONS as $extension) {
            try {
                DB::statement("CREATE EXTENSION IF NOT EXISTS {$extension}");
            } catch (Throwable $e) {
                // `CREATE EXTENSION` is a superuser (or `rds_superuser`) operation. On
                // managed PostgreSQL an ordinary app role cannot run it, and `IF NOT
                // EXISTS` does not help when the extension is merely unavailable. Fail
                // with the command a DBA needs to run rather than a raw SQLSTATE.
                throw new RuntimeException(sprintf(
                    'Could not create the "%s" extension: %s'
                    ."\n\n".'Ask a superuser to run:  CREATE EXTENSION IF NOT EXISTS %s;'
                    ."\n".'Then re-run this migration, or skip it entirely by publishing only'
                    .' the "scout-postgres-migrations" tag.',
                    $extension,
                    $e->getMessage(),
                    $extension,
                ), previous: $e);
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        // Intentionally not dropped. These extensions are shared infrastructure —
        // dropping `vector` would cascade away any vector column that still exists,
        // and this migration cannot assume it owns them.
    }

    /**
     * @var list<string>
     */
    private const array EXTENSIONS = ['vector', 'pg_trgm', 'unaccent'];
};
