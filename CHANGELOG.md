# Changelog

All notable changes to this package are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this
project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [1.1.1] - 2026-08-20

### Fixed

- **The bundled skill is now discoverable by `php artisan boost:install`.** It shipped in
  1.1.0 at `skills/using-scout-postgres-hybrid/SKILL.md`, which is the Claude Code plugin
  convention rather than the Laravel package one — Laravel Boost globs
  `<package>/resources/boost/skills/` and `<package>/resources/boost/guidelines/`, so it
  never saw the file and offered nothing. The skill now lives at
  `resources/boost/skills/scout-postgres-development/`, with its detail in
  `references/scout-postgres-guide.md` and a short `resources/boost/guidelines/core.blade.php`
  alongside it. The old directory is removed rather than duplicated, so the two cannot
  drift.

No code changed in this release.

## [1.1.0] - 2026-08-20

An independent adversarial review of 1.0.0 found nine defects, three of them
high-impact and one of them a tenant-crossing bug. All are fixed here. **Anyone running
1.0.0 in a multi-tenant configuration should upgrade.**

### Fixed

- **A malformed scope string could select a real tenant.** A scope passed as a string was
  cast straight to `int`, and PHP casts `'1-not-authorized'` to `1` — so an unvalidated
  value reaching `->options(['scope' => …])` returned tenant 1's documents, while `'acme'`
  became `0`. Non-integer scopes now go through `ScopeResolver::normalize()`, which throws
  for anything it does not recognise. `scout-postgres:reindex --scope` validates its
  argument for the same reason: with `--prune` the old behaviour deleted a tenant's
  documents on the strength of a typo.
- **A model whose scope column was not loaded is no longer silently skipped.** A partial
  `select()` leaves no scope attribute, which used to be read as "no scope", so deleting
  such a model skipped the purge and left its document searchable indefinitely. An absent
  attribute now throws; a scope that is genuinely empty is still skipped, which is a
  different case and correctly not an error.
- **Documents are purged when their scope no longer exists.** Reconciliation assumed
  `ON DELETE CASCADE` had already removed them, but the package also supports no foreign
  key at all and `ON DELETE SET NULL`. Under the first, a corpus outlived its tenant and
  stayed searchable under an id an adopter might reuse; under the second, rows survived
  with a null scope that no scoped query could ever reach again.
- **Scope resolution now happens before the PostgreSQL availability check.** Degrading to
  empty results off PostgreSQL is deliberate, but it must not double as a way to skip
  scope resolution — with the checks in the old order, an unresolvable scope returned
  empty instead of throwing whenever the connection was not PostgreSQL.

### Added

- `AGENTS.md` and `skills/using-scout-postgres-hybrid/SKILL.md`, agent-facing references
  covering the integration checklist, the invariants, the traps that are easy to get wrong
  (one model yields many documents; `[]` means delete; `DocumentType` must be compared by
  `value()` and never by identity), and an ordered checklist for diagnosing an empty
  result set.
- Tests for every defect above. The suite missed them because it only ever passed valid
  integer scopes and fully loaded models.

## [1.0.0] - 2026-08-20

First release. There is no prior history to list.

### Added

- A Laravel Scout engine registered as the `postgres` driver, fusing three retrieval
  branches into one ranked result set:
  - weighted `tsvector` full-text search, title and body carrying different weights;
  - `pg_trgm` word-similarity matching, for typo and inflection tolerance and as the
    only relevance signal in languages PostgreSQL ships no stemmer for;
  - `pgvector` cosine-distance semantic similarity.

  The three branches are combined with reciprocal rank fusion, so a document ranking
  moderately across all three outranks one that spikes in a single branch.
- Single-tenant and multi-tenant modes from one configuration key
  (`scope.mode` — `'none'` or `'column'`) and one conditional migration, rather than two
  separate schemas. The same `ScopeDefinition` object that builds the table is read by
  every query at runtime, so a table built in one mode cannot be queried in the other.
- The contracts an adopter implements to integrate: `ScopeResolver` (turns "the current
  tenant" into a scope key), `ScopeRepository` (answers whether a scope still exists, for
  reconciliation), `DocumentType` (one indexable kind of thing), `DocumentTypeRegistry`
  (the adopter's full set of types), and `EmbeddingProvider` (produces the vectors the
  semantic branch searches over). No default binding is provided for `ScopeResolver` or
  `ScopeRepository` — both describe the adopter's tenancy, and a stub that resolved some
  scope would silently defeat isolation, so their absence throws rather than guesses.
- `Core45\ScoutPostgres\Scope\CrossScope`, the one named path through which a
  platform-wide, cross-tenant read is allowed. It is constructed only via
  `CrossScope::platformWide(string $reason)`, so a bypass of scope isolation is
  deliberate and greppable rather than reachable by a stray boolean argument.
- `EmbeddingBackfill`, which fills the vectors the indexer deliberately does not write.
  Indexing runs synchronously on every model save, while embedding is a paid,
  rate-limited network round-trip; inlining it would put a provider outage on the write
  path of every model in the application. The indexer therefore nulls a vector whenever
  the text changes, and the backfill replaces it — run by `scout-postgres:reindex`, or
  queued by the adopter after their own sync job. Until something runs it, the semantic
  branch has no data.
- The `scout-postgres:reindex` console command, which rebuilds `search_documents` from
  the source models. Idempotent — it reconciles rather than inserts — so running it
  twice, or after a schema or config change, is the supported way to restate the corpus.
- A publishable config file (`scout-postgres-config`) and two separately publishable
  migrations: the schema migration (`scout-postgres-migrations`) and the extensions
  migration (`scout-postgres-extensions-migration`). The extensions migration is
  published separately because `CREATE EXTENSION` needs superuser privileges that an
  ordinary application role does not have on managed PostgreSQL (RDS, Cloud SQL,
  Supabase); an adopter whose DBA pre-creates `vector`, `pg_trgm` and `unaccent`
  publishes the schema migration alone.

### Requirements

PHP 8.3+, Laravel 12 or 13, Laravel Scout 11, and PostgreSQL 14+ with the `vector`,
`pg_trgm` and `unaccent` extensions installable.

### Known limitations

These are documented adopter requirements for this release, not configuration options.
See `docs/adr/0001-scope-abstraction-and-contracts.md` for the reasoning behind each.

- **Integer primary keys only.** `search_documents.searchable_id` is a `bigint`, and
  result ordering is built with `array_position(?::bigint[], …)`. Models keyed by UUID
  or ULID cannot be indexed.
- **Source models must carry the scope column.** Under `scope.mode => 'column'`,
  hydration filters the source model's own table as well as the document table, so every
  indexed model needs the scope column on its own table. There is no document-table-only
  isolation posture in this release.
- **Source keys must be unique across scopes, not merely within one.** The identity index
  is `(searchable_type, searchable_id, locale)` and deliberately excludes the scope
  column in both modes, so that one source model indexed under two tenants surfaces as a
  conflict rather than as two silently divergent rows.
- **No ANN index on the embedding column, by design.** pgvector post-filters `WHERE`
  against the approximate candidate set an ANN (HNSW/IVFFlat) index would return, which
  can hand back fewer rows than requested — or none — while matches exist. That is a
  correctness failure, not merely a slow query, so the semantic branch runs an exact
  `<=>` scan instead.
- **The default `EmbeddingProvider` is inert.** With no embedding provider bound, the
  semantic branch reports itself unready and search degrades to the keyword and trigram
  branches only. The package is installable and useful with no embedding infrastructure
  configured; the semantic branch switches on only once an adopter binds a real provider.

This release has not yet been proven by a production adopter.
