<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Scope
    |--------------------------------------------------------------------------
    |
    | How the document corpus is partitioned between tenants.
    |
    |   'column' — every document row carries a scope column (a tenant/shop id),
    |              and every query filters on it. Multi-tenant hosts want this.
    |   'none'   — single-tenant. The column is not created, and no query filters
    |              on it.
    |
    | This array is *input*. At boot it is hydrated into a ScopeDefinition value
    | object, which is what the migration and the engine both read — one source of
    | truth for the DDL and the runtime, so a table built in one mode cannot be
    | queried in the other. See docs D3 and SC-2.
    |
    | Hydrated by ScopeDefinition::fromConfig() when the service provider registers.
    | An unrecognised mode, a 'column' mode with no column, or a foreign key with no
    | table throws there rather than degrading into an unscoped query later.
    |
    */

    'scope' => [
        'mode' => env('SCOUT_POSTGRES_SCOPE_MODE', 'none'),
        'column' => 'tenant_id',
        'table' => 'tenants',
        'foreign_key' => true,
        'on_delete' => 'cascade',
        'nullable' => false,
    ],

    /*
    |--------------------------------------------------------------------------
    | Hydration
    |--------------------------------------------------------------------------
    |
    | Search returns keys; the models behind them are loaded from their own tables.
    | Those tables usually sit behind the host application's global scopes, and a
    | scope that throws with no bound tenant would break hydration on queues and in
    | console commands where the engine legitimately runs unbound.
    |
    | Name the global scopes to strip while hydrating. The default is empty: a
    | package cannot know these names, and stripping a scope the adopter did not
    | name would be the package widening a query on its own initiative. In the
    | host application the equivalent list is ['shop', 'shopAccess'].
    |
    | Isolation is not weakened by this, because it is not what enforces it: when
    | scope.mode is 'column' the engine applies its own scope predicate to the
    | source model's table (C3 — see the ADR: source models must carry the scope
    | column).
    |
    */

    'hydration' => [
        'strip_global_scopes' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Document types
    |--------------------------------------------------------------------------
    |
    | The kinds of thing this application indexes. Each entry is a class
    | implementing Core45\ScoutPostgres\Contracts\DocumentType; a backed enum is
    | expanded through ::cases(), anything else is constructed with no arguments.
    |
    |   'types' => [App\Enums\SearchableType::class],
    |
    | The package needs the full set for three things an interface cannot answer
    | on its own: which type a given model is indexed as, which type a stored
    | `searchable_type` string refers to, and what "every type" means for a
    | platform-wide count or an orphan sweep.
    |
    | Ships empty. Until at least one type is registered the engine can index
    | nothing, which is the correct default for a package that cannot know an
    | adopter's domain.
    |
    */

    'types' => [],

    /*
    |--------------------------------------------------------------------------
    | Cache
    |--------------------------------------------------------------------------
    |
    | Query embeddings are cached under a key built from this prefix, the scope, and
    | the embedding fingerprint. The prefix is configurable so two applications
    | sharing one cache store cannot collide (C7); the scope segment is emitted only
    | when scope.mode is 'column', so single-tenant installs get no empty segment.
    |
    */

    'cache' => [
        'prefix' => env('SCOUT_POSTGRES_CACHE_PREFIX', 'scout-postgres'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Locale → PostgreSQL text search configuration
    |--------------------------------------------------------------------------
    |
    | Maps an application locale to the `regconfig` used to build its tsvector.
    | This map only answers "given a locale, which stemmer?"; any locale absent
    | from it falls back to `default`.
    |
    | PostgreSQL ships no Polish configuration (`SELECT cfgname FROM pg_ts_config`
    | lists 30, none of them `polish`), which is why the shipped map sends `pl` to
    | `simple`, doing no stemming at all. The trigram branch compensates, but note
    | that is fuzzy lexical overlap, NOT morphology: it relates `joga`/`jogi`
    | because they share trigrams, not because it understands Polish. Any language
    | PostgreSQL has no stemmer for is in the same position.
    |
    | The indexer copies the resolved value into `search_documents.text_search_config`,
    | so changing an entry here does NOT restem anything already indexed — it
    | REQUIRES a full reindex. Queries built with one config against vectors built
    | with another silently return nothing.
    |
    */

    'text_search' => [
        'default' => env('SEARCH_TEXT_CONFIG_DEFAULT', 'simple'),

        'configs' => [
            'de' => 'german',
            'en' => 'english',
            'es' => 'spanish',
            'fr' => 'french',
            'it' => 'italian',
            'pl' => 'simple', // no Polish stemmer exists in PostgreSQL
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Trigram matching
    |--------------------------------------------------------------------------
    |
    | Typo and inflection tolerance. PostgreSQL full-text search has none of its
    | own, so `pg_trgm` is what provides it.
    |
    | This is `word_similarity`, NOT `similarity`, and the difference decides
    | whether search works at all in a language without a stemmer. Measured on
    | 'joga mata do cwiczen':
    |
    |   query   similarity   word_similarity
    |   jogi        0.130          0.600
    |   joga        0.238          1.000
    |   yoga        0.083          0.400
    |
    | `similarity()` normalises over the union of both trigram sets, so a short
    | query against a multi-word title is diluted below any usable threshold —
    | every one of those queries fails at 0.25. `word_similarity()` normalises over
    | the query's best matching extent, so they all pass at 0.4.
    |
    | The query form MUST be `trigram_text %> :query`, column on the left. That is
    | the commutator of `:query <% trigram_text` and the only form the GIN index
    | serves. Writing `trigram_text <% :query` inverts the operands and silently
    | returns ZERO rows.
    |
    */

    'trigram' => [
        // Applied as: SET LOCAL pg_trgm.word_similarity_threshold = <value>
        'word_similarity_threshold' => (float) env('SEARCH_TRIGRAM_THRESHOLD', 0.4),
    ],

    /*
    |--------------------------------------------------------------------------
    | Synonyms
    |--------------------------------------------------------------------------
    |
    | Applied as query expansion before the tsquery is built.
    |
    | Ships EMPTY on purpose. Cross-language pairs are the reason this cannot be
    | derived — `health` and `zdrowie` share no trigrams and no stemmer relates
    | them — but they are also entirely specific to one application's catalogue,
    | so a package that shipped its author's pairs would be shipping their domain.
    | Inflectional variants are largely handled by the trigram branch already; add
    | entries here only where that is not enough.
    |
    |   'yoga' => ['joga', 'jogi'],
    |
    */

    'synonyms' => [],

    /*
    |--------------------------------------------------------------------------
    | Hybrid ranking (reciprocal rank fusion)
    |--------------------------------------------------------------------------
    |
    | The keyword, trigram and semantic branches are ranked independently and
    | fused as sum(weight / (k + rank)), so a document ranking moderately in all
    | three beats one that spikes in a single branch. `k` damps the influence of
    | low-ranked hits; 60 is the value from the original RRF paper.
    |
    */

    'hybrid' => [
        'k' => (int) env('SEARCH_RRF_K', 60),

        'weights' => [
            'keyword' => (float) env('SEARCH_WEIGHT_KEYWORD', 1.0),
            'semantic' => (float) env('SEARCH_WEIGHT_SEMANTIC', 1.0),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | `dimensions` is read by the published migration, because
    | `search_documents.embedding` is a typed `vector(N)` column. It must match the
    | width your EmbeddingProvider returns. Changing it after rows exist is not a
    | `Schema::table()` operation — see the README's upgrade policy.
    |
    | Which provider and model produce the vectors is deliberately NOT configured
    | here. That is the adopter's EmbeddingProvider implementation, which may well
    | vary per tenant; the package only asks it for a vector and a fingerprint.
    |
    | A provider that returns a vector of the wrong width fails the dimension
    | check, the row is stored with a NULL embedding, and searches for it degrade
    | to keyword plus trigram rather than failing.
    |
    */

    'vector' => [
        'enabled' => (bool) env('SEARCH_VECTOR_ENABLED', true),
        'dimensions' => (int) env('SEARCH_EMBEDDING_DIMENSIONS', 1536),
        'min_similarity' => (float) env('SEARCH_MIN_SIMILARITY', 0.4),

        /*
        | Query-side embedding cache.
        |
        | Embedding the *query* is a synchronous provider round-trip on the request
        | path, measured at roughly 210ms against OpenAI. Unlike the write side,
        | nothing can queue it: a hybrid search cannot rank semantically without it.
        |
        | `query_cache_ttl` caches the vector for a query string. Search traffic is
        | heavily repetitive, so this is the difference between one provider call
        | per distinct term and one per request. The key includes the provider's
        | fingerprint, so switching model does not keep serving vectors from the
        | old space.
        |
        | `unavailable_ttl` is the negative half, and it is the one that matters
        | during an incident. A provider that is down or rate-limited still costs a
        | full round-trip before returning nothing, so without this every search
        | pays that latency to produce no semantic branch at all. Caching the
        | failure means an outage costs one attempt per interval instead of one per
        | search. Keep it short: it is also how long a recovered provider stays
        | unused.
        */
        'query_cache_ttl' => (int) env('SEARCH_QUERY_VECTOR_TTL', 3600),
        'unavailable_ttl' => (int) env('SEARCH_VECTOR_UNAVAILABLE_TTL', 60),
    ],

    /*
    |--------------------------------------------------------------------------
    | Indexed body length
    |--------------------------------------------------------------------------
    |
    | `to_tsvector` raises an error above 1MB of input and silently discards lexeme
    | positions past 16383 — and `ts_rank_cd` ranks by proximity, so a document
    | whose positions were dropped ranks wrongly without ever raising anything.
    | `SearchDocumentData` truncates `body` to this many characters on construction,
    | well below both limits.
    |
    | Set to 0 to disable truncation entirely (not recommended).
    |
    */

    'max_body_length' => (int) env('SEARCH_MAX_BODY_LENGTH', 100_000),

    /*
    |--------------------------------------------------------------------------
    | Index maintenance queue
    |--------------------------------------------------------------------------
    |
    | Where the document sync job runs. Index work is high-volume and entirely
    | non-urgent — one model edit can fan out to a row per locale — so it is worth
    | keeping off whatever queue serves user-visible work.
    |
    */

    'queue' => env('SEARCH_QUEUE', 'default'),

];
