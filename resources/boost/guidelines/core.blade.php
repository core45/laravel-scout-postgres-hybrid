{{-- Laravel Scout Postgres Hybrid Guidelines for AI Code Assistants --}}
{{-- Source: https://github.com/core45/laravel-scout-postgres-hybrid --}}
{{-- License: MIT | (c) core45 --}}

## Scout Postgres Hybrid

- `core45/laravel-scout-postgres-hybrid` is a Laravel Scout `postgres` driver that fuses weighted `tsvector` full-text search, `pg_trgm` trigram similarity, and `pgvector` cosine similarity into one ranked result set via reciprocal rank fusion.
- Always activate the `scout-postgres-development` skill when working with the `Core45\ScoutPostgres\` namespace, `SearchableWithoutSyncing`, `toSearchDocuments()`, `PostgresSearchService`, `SearchIndexer`, `EmbeddingBackfill`, `CrossScope`, the `scout-postgres:reindex` artisan command, or `config/scout-postgres.php`.
