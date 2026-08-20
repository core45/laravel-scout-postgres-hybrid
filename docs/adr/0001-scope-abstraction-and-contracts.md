# 0001 — Scope abstraction and contracts

**Status:** Accepted, 2026-08-20

## Context

The package extracts the PostgreSQL Scout engine out of primatech-multi. Tenancy is the risky
coupling of the extraction (plan §3, §4): the host's `shop_id`/`Shop` welding runs through the
indexer, the search service and the semantic service, and the planned migration shipped a
`foreignId('shop_id')->constrained()->cascadeOnDelete()` that hard-codes a `shops` table even
while the surrounding plan advertised configurable tenancy. §4.1 enumerates eight couplings,
C1 through C8, that any scope abstraction must cover to be more than cosmetic. §4.2 states three
invariants, SC-1 through SC-3, that the abstraction must hold regardless of how the couplings are
resolved. §4.3 records five decisions, D1 through D5, taken against a spike that closed the
schema question but left four questions for a human.

This ADR is the P1 gate: the contracts compile, and this document covers C1–C8 against the code
that implements them. It records decisions already taken; it does not re-open them.

## C1 — Schema FK to `shops`

**Coupling (plan §4.1):** the scope column, its FK, and whether one is created at all must be
declared by config rather than hard-coded to `shops.id`.

**Evidence:** primatech-multi migration `:34`, `$table->foreignId('shop_id')->constrained()->cascadeOnDelete();`.

**Decision:** the scope column is declared by `ScopeDefinition`, hydrated from
`config/scout-postgres.php` by `ScopeDefinition::fromConfig()`
(`src/Scope/ScopeDefinition.php:134-191`). Two legal shapes exist:
`ScopeDefinition::column('shop_id')->constrainedTo('shops')->onDelete('cascade')` and
`ScopeDefinition::none()` (D3, plan §4.3). `column()` is defined at
`src/Scope/ScopeDefinition.php:62-67`, `none()` at `src/Scope/ScopeDefinition.php:75-78`,
`constrainedTo()` at `src/Scope/ScopeDefinition.php:83-89`. Per D4 the migration will emit an
explicit `foreign()->references()`, never `constrained()`, so the constraint's target table is
stated rather than inferred from the column name — recorded in the `onDelete()` docblock at
`src/Scope/ScopeDefinition.php:91-94`.

**Package artifact:** `src/Scope/ScopeDefinition.php`, hydrated as a singleton in
`src/ScoutPostgresServiceProvider.php:32-36` from `config/scout-postgres.php:30-37`.

**Not solved in v0.1:** the migration itself. `ScopeDefinition` is the value the migration will
read, but the conditional create-table migration is P2 work and does not exist in this repo yet.

## C2 — Host global-scope names as string literals

**Coupling (plan §4.1):** a package cannot know the host's global scope names; they must be
config, defaulting to none.

**Evidence:** `PostgresSearchService.php:222-223`, `SearchIndexer.php:395-396`,
`SearchReconciliation.php:118-119`, each calling `->withoutGlobalScope('shop')->withoutGlobalScope('shopAccess')`.

**Decision:** a new config key, `hydration.strip_global_scopes`
(`config/scout-postgres.php:61-63`), lists global scope names to strip at hydration, defaulting
to the empty list. The package will not guess; primatech-multi must set its own values,
`['shop', 'shopAccess']`, in its published config. Isolation does not depend on this list: the
config comment at `config/scout-postgres.php:54-57` states that in `column` mode the engine
applies its own scope predicate to the source model's table regardless of what global scopes are
stripped — stripping only removes a redundant, possibly-throwing filter during hydration, it does
not open the query.

**Package artifact:** `config/scout-postgres.php:61-63`. No corresponding code in this repo reads
the key yet; hydration is P2/P5 work.

**Not solved in v0.1:** the hydration code path that consumes this list. Only the config surface
exists here.

## C3 — Source models must carry the scope column

**Coupling (plan §4.1):** `PostgresSearchService.php:224` applies `->where('shop_id', …)` to the
*source model's* table, not `search_documents`, so isolation depends on the source model owning
the scope column. §4.1 floats an alternative: an opt-out that pins the scope through the document
table only.

**Evidence:** `PostgresSearchService.php:224`.

**Decision:** a **documented adopter requirement** for v0.1, not a configurable opt-out. In
`column` mode, every source model backing a `DocumentType` (`src/Contracts/DocumentType.php`)
must have the scope column on its own table, because hydration filters the source table as well
as the document table. The opt-out that pins isolation through the document table alone is a
second isolation posture, and the plan's gate (§4.2) requires the tenant-isolation suite to pass
unmodified in two configurations — adding a second posture doubles that surface for no v0.1
adopter who needs it. It is considered and rejected here, deferred to v0.2.

**Package artifact:** none directly enforces this in code yet — the constraint is that hydration
(P2/P5) will read the scope column off the source table, which makes the requirement structural
once written. `config/scout-postgres.php:54-57` documents it inline.

**Not solved in v0.1:** the document-table-only isolation posture is not built, and there is no
runtime check that a source model actually carries the column — an adopter who violates this
requirement gets a SQL error or a scope leak at hydration time, not a boot-time failure.

## C4 — Integer primary keys

**Coupling (plan §4.1):** `PostgresSearchService.php:232` builds `array_position(?::bigint[], …)`
against `searchable_id`, which is `unsignedBigInteger`; UUID/ULID adopters get a SQL type error
unless the cast derives from the model's key type.

**Evidence:** `PostgresSearchService.php:232`.

**Decision:** **bigint only for v0.1**. `searchable_id` stays `unsignedBigInteger`, the scope
column is `unsignedBigInteger`, and `ScopeResolver` returns `int` rather than `int|string`
(`src/Contracts/ScopeResolver.php:37`, `:49`, and the type-floor note at `:24-25`). Consequence,
stated plainly: UUID and ULID adopters cannot use v0.1. The scope column type is therefore
deliberately **not** a config key, even though §4.1's required contract for C1 lists "type" among
the things config should declare — this is a direct consequence of the C4 decision, recorded
here rather than in C1 because it is C4 that forces it. Deriving the array cast from the model's
key type, the alternative the P1 checklist offers, is deferred to v0.2:
`array_position(?::bigint[], …)` at `PostgresSearchService.php:232` is one of several sites, and
the cast must be derived consistently across all of them, which is more than a P1-scoped change.

**Package artifact:** `src/Contracts/ScopeResolver.php:37` (`current(): int`), `:49`
(`normalize(mixed $scope): int`); `ScopeDefinition` carries no column-type parameter
(`src/Scope/ScopeDefinition.php:48-54`).

**Not solved in v0.1:** UUID/ULID primary keys, and the derived-cast mechanism needed to support
them.

## C5 — Deliberate cross-scope bypass

**Coupling (plan §4.1):** `SearchIndexStatistics.php:146` calls `withoutGlobalScopes()` with no
scope predicate, commented "uniquely in this namespace" — a real requirement, not a leak, but one
that needs a named API so it cannot be reached by accident.

**Evidence:** `SearchIndexStatistics.php:146`.

**Decision:** the named API is `Core45\ScoutPostgres\Scope\CrossScope`, constructed only via
`CrossScope::platformWide(string $reason)` (`src/Scope/CrossScope.php:37-40`). A distinct type
was chosen over a boolean flag on an existing method for two reasons, both recorded in the class
docblock (`src/Scope/CrossScope.php:16-21`): SC-3 must be architecture-testable, and a flag is
reachable by accident — `true` in the wrong argument position silently widens a query — whereas
constructing this class is deliberate and greppable, and a type is what an architecture test can
assert on. The rule to be enforced in P2/P6: no package code calls `withoutGlobalScopes()` outside
a method whose signature names `CrossScope`. The constructor's `$reason` parameter is mandatory
and non-empty (`src/Scope/CrossScope.php:29-32`, `:35-36`) and never reaches a query — it exists
purely as a written justification a reviewer can weigh at the call site.

**Package artifact:** `src/Scope/CrossScope.php`.

**Not solved in v0.1:** the architecture test that enforces the P2/P6 rule does not exist in this
repo yet, and no package code calling `withoutGlobalScopes()` exists yet either — there is nothing
for the rule to check against until the engine lands.

## C6 — Scope-existence probe

**Coupling (plan §4.1):** `SearchIndexer.php:97` guards `reconcile()` with
`Shop::query()->withoutGlobalScopes()->whereKey($shopId)->exists()`; the package cannot query a
`Shop`, so it needs a `ScopeRepository::exists()` contract.

**Evidence:** `SearchIndexer.php:97`.

**Decision:** `ScopeRepository::exists(int $scope): bool`
(`src/Contracts/ScopeRepository.php:28`). Its docblock requires implementations to bypass the
host's own tenant global scopes (`src/Contracts/ScopeRepository.php:19-22`), and states why:
reconciliation runs unbound on queues and in console commands, so a scoped lookup would report
every scope as missing and turn the guard into a silent no-op for all reindexing — the opposite
of the host's current `withoutGlobalScopes()` call, which the contract exists to reproduce
faithfully rather than accidentally tighten.

**Package artifact:** `src/Contracts/ScopeRepository.php`.

**Not solved in v0.1:** no implementation ships; primatech-multi's `Shop`-backed implementation is
adopter code, and `SearchIndexer::reconcile()` itself is not yet ported (P2).

## C7 — Cache key namespace

**Coupling (plan §4.1):** `SemanticSearchService.php:97` builds a cache key as
`'search:vector:query:'.$shop->getKey().':'.$fingerprint.':'.hash(…)`; correctly scoped today, but
the prefix is a global literal and single-tenant mode has no natural segment.

**Evidence:** `SemanticSearchService.php:97`.

**Decision:** a `cache.prefix` config key (`config/scout-postgres.php:77-79`), plus
`ScopeDefinition::cacheSegment(int $scope)` (`src/Scope/ScopeDefinition.php:224-227`), which
returns the empty string in `none` mode rather than a dangling separator — documented at
`src/Scope/ScopeDefinition.php:219-223` — so single-tenant installs get no malformed or colliding
key, and two applications sharing one cache store cannot collide on the prefix either
(`config/scout-postgres.php:70-75`).

**Package artifact:** `src/Scope/ScopeDefinition.php:224-227`, `config/scout-postgres.php:77-79`.

**Not solved in v0.1:** no cache-reading code exists yet in this repo; `cacheSegment()` is
unconsumed until the semantic service is ported.

## C8 — Key uniqueness domain

**Coupling (plan §4.1):** migration `:73-76` excludes the scope column from the unique index
deliberately; `SearchIndexer::upsert()` conflicts on `(searchable_type, searchable_id, locale)`,
so a source key unique only within a tenant becomes a legitimate duplicate row across tenants.

**Evidence:** primatech-multi migration `:73-76`; `SearchIndexer::upsert()`.

**Decision:** unchanged from D2 (plan §4.3) — the unique index ships fixed as
`(searchable_type, searchable_id, locale)` and scope-excluded in both modes. This is a documented
adopter requirement, not a supported configuration: an adopter whose source keys are unique only
within a tenant cannot use v0.1. D2 rejects the scope-inclusive-in-column-mode alternative
explicitly, on the grounds that it costs no new config but destroys the cross-tenant drift
detection the exclusion exists to preserve.

**Package artifact:** none in this repo — the unique index is part of the P2 migration, not yet
written. This section records the decision the migration must implement.

**Not solved in v0.1:** a configurable uniqueness domain. In v0.2 this becomes a
`ScopeDefinition` method per D2, not a config key.

## Consequences

**SC-1.** `ScopeResolver::current()` and `::normalize()` both return `int` and never `null`
(`src/Contracts/ScopeResolver.php:37`, `:49`); `UnresolvableScope` is thrown instead
(`src/Exceptions/UnresolvableScope.php`). The two alternatives to throwing — dropping the scope
predicate, or forcing an empty result — look identical to the caller, "no matches", while being
respectively a cross-tenant leak and a silent outage. `UnresolvableScope`'s own docblock states
this directly (`src/Exceptions/UnresolvableScope.php:9-19`) and cites the host's
`PostgresDocumentEngine::requireShop()` as the precedent already failing closed for the same
reason. `ScopeDefinition::requireColumn()` applies the identical discipline one level down: it
throws rather than returning `null` so a forgotten `isScoped()` guard cannot silently produce an
unfiltered query (`src/Scope/ScopeDefinition.php:203-217`).

**SC-2.** `ScopeDefinition::fromConfig()` (`src/Scope/ScopeDefinition.php:134-191`) is not a
database probe. A boot-time schema query would fail on a fresh install, before `migrate` has run
— stated in the class docblock (`src/Scope/ScopeDefinition.php:19-24`) and repeated in the
service provider's binding comment (`src/ScoutPostgresServiceProvider.php:23-31`). Agreement
between DDL and runtime is structural, not checked: `ScopeDefinition` is bound as a singleton
(`src/ScoutPostgresServiceProvider.php:32-36`), so the same object the migration will read is the
one every runtime consumer reads — there is no second place for them to disagree, which is a
different guarantee from validating that they agree. What `fromConfig()` actually does is reject
incoherent input. Reading `src/Scope/ScopeDefinition.php:134-191` and
`src/Exceptions/InvalidScopeConfiguration.php`, it throws when:

- `mode` is missing, not a string, or neither `"column"` nor `"none"` — `unknownMode()`
  (`ScopeDefinition.php:136-139`, `:148-150`; `InvalidScopeConfiguration.php:20-27`).
- `mode` is `"column"` but `column` is missing, not a string, or blank after trimming —
  `missingColumn()` (`ScopeDefinition.php:152-156`; `InvalidScopeConfiguration.php:29-35`).
- `column` or `table` fails the unquoted-identifier pattern
  `/^[a-z_][a-z0-9_$]*$/i` — `invalidIdentifier()` (`ScopeDefinition.php:236-241`;
  `InvalidScopeConfiguration.php:45-54`), because the value is interpolated into DDL rather than
  escaped.
- `nullable` is present but not a boolean — `invalidType('nullable', …)`
  (`ScopeDefinition.php:160-164`; `InvalidScopeConfiguration.php:73-81`).
- `foreign_key` is present but not a boolean — `invalidType('foreign_key', …)`
  (`ScopeDefinition.php:168-172`).
- `foreign_key` is `true` but `table` is missing or blank — `missingForeignTable()`
  (`ScopeDefinition.php:178-182`; `InvalidScopeConfiguration.php:37-43`).
- `on_delete` is present but not a string — `invalidOnDelete()`
  (`ScopeDefinition.php:184-188`; `InvalidScopeConfiguration.php:56-63`), or is a string outside
  `ScopeDefinition::ON_DELETE_ACTIONS` (`cascade`, `restrict`, `set null`, `no action`) — checked
  in `onDelete()` (`ScopeDefinition.php:99-103`).
- `on_delete` is `"set null"` while `nullable` is `false` — `setNullRequiresNullableColumn()`
  (`ScopeDefinition.php:105-107`; `InvalidScopeConfiguration.php:65-71`), since `set null`
  requires a nullable column to be a legal referential action.

It does **not** check that the named table or column exists, that a migration has run, or that
the database is even reachable — none of that is possible at boot on a fresh install, and
`fromConfig()`'s job is limited to rejecting input that could not possibly be coherent regardless
of database state.

The shipped default is `mode => 'none'` (`config/scout-postgres.php:31`) while `column`, `table`,
`foreign_key` and `on_delete` remain populated in the published file
(`config/scout-postgres.php:32-36`). In `none` mode those sibling keys are **inert, not
contradictory**: `fromConfig()` returns `self::none()` as soon as it sees `mode === 'none'`
(`ScopeDefinition.php:144-146`) without inspecting `column`, `table`, `foreign_key` or
`on_delete` at all, so populated-but-unused values never throw. They document the shape a
multi-tenant adopter fills in (`ScopeDefinition.php:127-130`). This follows from SC-1: because
single-tenant is a configured state rather than the absence of configuration, the sibling keys
being present-but-unread is exactly what "configured but not selected" looks like, not an error.

**SC-3.** Enforced by the `CrossScope` type (`src/Scope/CrossScope.php`) plus an architecture
test owed in P2 — see C5 above. Neither the rule-enforcing test nor any call site exists in this
repo yet.

## Adopter requirements

These are C3, C4 and C8, restated together because the plan's P7 groups them the same way:

- **C3** — every source model backing a `DocumentType` must carry the scope column on its own
  table when `scope.mode` is `column`. There is no document-table-only isolation posture in v0.1.
- **C4** — primary keys must be integer (bigint). UUID and ULID adopters cannot use v0.1.
- **C8** — source keys must be globally unique, not merely unique within a tenant, because the
  unique index `(searchable_type, searchable_id, locale)` excludes the scope column in both
  modes.

## Open for v0.2

- C3: a document-table-only isolation posture as a configurable opt-out.
- C4: deriving the `searchable_id` array cast from the model's key type, to support UUID/ULID,
  applied consistently across every cast site (`PostgresSearchService.php:232` and others not yet
  enumerated in this repo).
- C8: a `ScopeDefinition` method to make the unique index scope-inclusive in `column` mode, for
  adopters whose source keys are only tenant-unique.
- D1's `search_documents_lookup_idx` is recorded in the plan as unmeasured and revisable on the
  P7 performance baseline; this ADR does not re-open it, but the migration that will carry it is
  not in this repo yet, so there is nothing here to measure against.
