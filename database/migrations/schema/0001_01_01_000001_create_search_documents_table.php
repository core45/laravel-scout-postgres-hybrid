<?php

declare(strict_types=1);

use Core45\ScoutPostgres\Scope\ScopeDefinition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The denormalised search corpus. One row per (searchable model, locale).
     *
     * The whole migration is pgsql-only, and not as the usual "skip an optimisation
     * elsewhere" guard: `Schema::ensureVectorExtensionExists()` throws outright off
     * PostgreSQL, as does the base grammar's `vector` type. An adopter whose default
     * connection is not PostgreSQL gets no table rather than a failed `migrate`, which
     * matches the engine's own behaviour of degrading to empty results off pgsql.
     *
     * Both scope modes come from one conditional migration rather than two publishable
     * files (D3). The mode is read from the `ScopeDefinition` singleton, which is the
     * same object every runtime consumer reads — that shared read is the whole of SC-2,
     * since there is no second place for the DDL and the queries to disagree about the
     * column's name, type or nullability.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $scope = app(ScopeDefinition::class);
        $dimensions = $this->dimensions();

        Schema::ensureVectorExtensionExists();

        Schema::create('search_documents', function (Blueprint $table) use ($scope, $dimensions): void {
            $table->id();

            if ($scope->isScoped()) {
                // D4: an explicit foreign key rather than `foreignId()->constrained()`,
                // which infers a table name from the column and would weld the package
                // to whatever `shop_id` happens to imply. C4 pins the type to bigint for
                // v0.1, so the scope column is not type-configurable.
                $column = $table->unsignedBigInteger($scope->requireColumn());

                if ($scope->nullable) {
                    $column->nullable();
                }

                if ($scope->hasForeignKey()) {
                    $table->foreign($scope->requireColumn())
                        ->references('id')
                        ->on((string) $scope->foreignTable)
                        ->onDelete((string) $scope->onDeleteAction);
                }
            }

            // A DocumentType value, and the source model's key.
            $table->string('searchable_type', 64);
            $table->unsignedBigInteger('searchable_id');

            // BCP-47 locale, or DocumentType::LOCALE_ANY ('*') for types that are not
            // translatable.
            $table->string('locale', 10);

            // The PostgreSQL regconfig NAME (e.g. 'english', 'simple'), not the locale.
            // The trigger below casts this to regconfig, and a locale cannot be cast
            // directly — 'pl' is not a config name — which is why the column exists.
            $table->string('text_search_config', 32);

            $table->text('title')->nullable();
            $table->text('body')->nullable();

            // Pre-normalised trigram haystack: unaccented, lowercased, NOT NULL. Kept
            // separate from title/body because `a || b` is NULL when either side is
            // NULL, which would silently drop the row out of the trigram index, and
            // because whole-string similarity() is diluted to uselessness by long body
            // text.
            $table->text('trigram_text');

            // sha256 of the resolved title+body. Two locales of a model with a single
            // translation resolve to identical text, so the indexer generates one
            // embedding and copies it rather than paying for the same vector twice. An
            // unchanged hash also means no work.
            $table->char('content_hash', 64);

            $table->jsonb('filters')->default('{}');

            $table->vector('embedding', dimensions: $dimensions)->nullable();

            // Identifies the provider and model that produced `embedding`. Vectors from
            // two different models are not comparable, and a distance between them is a
            // plausible-looking number that means nothing, so a changed fingerprint
            // marks the row as stale rather than silently ranking it wrongly.
            $table->string('embedding_fingerprint', 191)->nullable();

            $table->timestamps();

            // D2: deliberately excludes the scope column, in both modes. Model keys are
            // assumed global (C8), so including the scope would let one source model be
            // indexed under two tenants instead of surfacing that as the drift it is.
            $table->unique(['searchable_type', 'searchable_id', 'locale']);

            // D1: the lookup index degrades to (searchable_type, locale) in `none` mode.
            // Named identically in both modes, because the name is pinned and nothing
            // asserts on it. Recorded in the plan as low-confidence and revisable on the
            // P7 performance baseline: the unique index above shares its leading column,
            // so this earns its write cost only where `locale` must be seekable as the
            // second column.
            $table->index(
                $scope->isScoped()
                    ? [$scope->requireColumn(), 'searchable_type', 'locale']
                    : ['searchable_type', 'locale'],
                'search_documents_lookup_idx',
            );

            $table->index('content_hash');
        });

        $this->createImmutableUnaccent();
        $this->createSearchVector();
        $this->createIndexes();
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        Schema::dropIfExists('search_documents');

        DB::statement('DROP FUNCTION IF EXISTS search_documents_update_vector()');

        // f_unaccent is deliberately left in place: it is a general-purpose helper and
        // another index in the adopter's schema may already depend on it.
    }

    /**
     * Embedding width, from config rather than a constant, because it is a property of
     * the adopter's embedding model. Changing it after rows exist is not a `Schema::table()`
     * operation — see the README's upgrade policy.
     */
    private function dimensions(): int
    {
        $dimensions = config('scout-postgres.vector.dimensions', 1536);

        return is_int($dimensions) ? $dimensions : 1536;
    }

    /**
     * `unaccent()` is STABLE, not IMMUTABLE, so it cannot appear in an index expression
     * ("functions in index expression must be marked IMMUTABLE"). Pinning the dictionary
     * by name makes the wrapper genuinely immutable.
     */
    private function createImmutableUnaccent(): void
    {
        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION f_unaccent(text)
            RETURNS text AS
            $func$
                SELECT public.unaccent('public.unaccent', $1)
            $func$
            LANGUAGE sql IMMUTABLE PARALLEL SAFE STRICT
        SQL);
    }

    /**
     * The weighted tsvector. Weight 'A' on the title and 'D' on the body, so a hit in a
     * product name outranks one buried in a 5000-word description.
     */
    private function createSearchVector(): void
    {
        DB::statement('ALTER TABLE search_documents ADD COLUMN search_vector tsvector');

        DB::statement(<<<'SQL'
            CREATE OR REPLACE FUNCTION search_documents_update_vector()
            RETURNS trigger AS
            $func$
            BEGIN
                NEW.search_vector :=
                    setweight(to_tsvector(NEW.text_search_config::regconfig, coalesce(NEW.title, '')), 'A') ||
                    setweight(to_tsvector(NEW.text_search_config::regconfig, coalesce(NEW.body, '')), 'D');

                RETURN NEW;
            END;
            $func$
            LANGUAGE plpgsql
        SQL);

        DB::statement(<<<'SQL'
            CREATE TRIGGER search_documents_vector_update
            BEFORE INSERT OR UPDATE OF title, body, text_search_config
            ON search_documents
            FOR EACH ROW
            EXECUTE FUNCTION search_documents_update_vector()
        SQL);
    }

    /**
     * No ANN (HNSW/IVFFlat) index on `embedding`. pgvector post-filters WHERE against the
     * approximate candidate set, so a query scoped to one tenant and type can return
     * fewer rows than requested, or none, while matches exist. That is a correctness
     * failure, not a slow query. Exact `<=>` over this corpus has perfect recall.
     */
    private function createIndexes(): void
    {
        DB::statement('CREATE INDEX search_documents_search_vector_idx ON search_documents USING GIN (search_vector)');
        DB::statement('CREATE INDEX search_documents_trigram_idx ON search_documents USING GIN (trigram_text gin_trgm_ops)');
        DB::statement('CREATE INDEX search_documents_filters_idx ON search_documents USING GIN (filters)');
    }
};
