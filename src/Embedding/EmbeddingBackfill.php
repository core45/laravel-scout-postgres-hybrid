<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Embedding;

use Core45\ScoutPostgres\Contracts\EmbeddingProvider;
use Core45\ScoutPostgres\Exceptions\UnresolvableScope;
use Core45\ScoutPostgres\Scope\ScopeDefinition;
use Illuminate\Support\Facades\DB;

/**
 * Fills in the vectors the indexer deliberately does not write.
 *
 * The indexer nulls `embedding` whenever `content_hash` changes and never writes
 * one itself, which is the right split: indexing is synchronous and happens on
 * every save, while embedding is a paid, rate-limited, network round-trip. Doing
 * it inline would put a provider outage on the write path of every model in the
 * application.
 *
 * The consequence is that something has to run this, or the semantic branch has
 * no data and the engine is a two-branch hybrid forever. `scout-postgres:reindex`
 * calls it; an adopter wanting embeddings to appear without a reindex should
 * queue it after their sync job.
 *
 * Two things it will not do. It does not re-embed rows whose vector is current,
 * because that is the expensive mistake this class exists to avoid. And it does
 * not touch rows outside the given scope, for the usual reason: a backfill that
 * lost its scope predicate would read and rewrite every tenant's corpus.
 */
final class EmbeddingBackfill
{
    public function __construct(
        private readonly EmbeddingProvider $embeddings,
        private readonly ScopeDefinition $scope,
    ) {}

    /**
     * Embed up to `$limit` documents that need it.
     *
     * "Need it" is a null vector *or* one carrying a different fingerprint: a
     * vector produced by another model is not comparable with the current one, and
     * a distance between the two is a plausible-looking number that means nothing.
     *
     * @return int how many rows were embedded
     */
    public function run(?int $scope, int $limit = 500): int
    {
        if (! $this->embeddings->isReady()) {
            // Not an error. The shipped default provider is inert on purpose, so
            // an adopter with no embedding infrastructure gets keyword plus
            // trigram rather than a failure.
            return 0;
        }

        $scope = $this->requireScope($scope);
        $fingerprint = $this->embeddings->fingerprint();

        $rows = $this->pending($scope, $fingerprint, $limit);

        // Cache by content hash within the run. A model translated once but indexed
        // under several locales resolves to identical text in each, and paying the
        // provider per locale for one vector is the cost this avoids.
        $vectors = [];
        $embedded = 0;

        foreach ($rows as $row) {
            $hash = (string) $row->content_hash;

            if (! array_key_exists($hash, $vectors)) {
                $text = trim((string) $row->title."\n".(string) $row->body);
                $vectors[$hash] = $text === '' ? null : $this->embeddings->embed($text);
            }

            $vector = $vectors[$hash];

            if ($vector === null) {
                continue;
            }

            $embedded += $this->store((int) $row->id, $vector, $fingerprint);
        }

        return $embedded;
    }

    /**
     * @return list<object>
     */
    private function pending(?int $scope, string $fingerprint, int $limit): array
    {
        $sql = 'SELECT id, title, body, content_hash FROM search_documents WHERE (embedding IS NULL OR embedding_fingerprint IS DISTINCT FROM ?)';
        $bindings = [$fingerprint];

        if ($this->scope->isScoped()) {
            $sql .= ' AND '.$this->scope->requireColumn().' = ?';
            $bindings[] = $scope;
        }

        $sql .= ' ORDER BY id LIMIT ?';
        $bindings[] = $limit;

        return DB::select($sql, $bindings);
    }

    /**
     * @param  list<float>  $vector
     */
    private function store(int $id, array $vector, string $fingerprint): int
    {
        // Encoded as pgvector's own text form and cast, rather than bound as an
        // array: there is no PDO type for `vector`, and the cast is what the
        // column's input function expects.
        $encoded = '['.implode(',', array_map(static fn (float $v): string => (string) $v, $vector)).']';

        return DB::update(
            'UPDATE search_documents SET embedding = ?::vector, embedding_fingerprint = ? WHERE id = ?',
            [$encoded, $fingerprint, $id],
        );
    }

    private function requireScope(?int $scope): ?int
    {
        if (! $this->scope->isScoped()) {
            return null;
        }

        if ($scope === null) {
            throw UnresolvableScope::noAmbientScope();
        }

        return $scope;
    }
}
