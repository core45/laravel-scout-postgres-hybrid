<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Scope;

use Core45\ScoutPostgres\Exceptions\InvalidScopeConfiguration;
use Core45\ScoutPostgres\Exceptions\UnresolvableScope;

/**
 * How the document corpus is partitioned, as one value rather than six array reads.
 *
 * D3: `config/scout-postgres.php` is the *input*; this object is what the published
 * migration, the search service, the indexer and the semantic service all depend on.
 * Because both the DDL and the runtime read the same object, they cannot disagree
 * about the column's name, nullability or foreign key — agreement is a property of
 * the construction, not of two code paths happening to read the same array key.
 *
 * That is the whole of SC-2, and it is deliberately *not* a database probe. A boot
 * time check that queried the schema would fail on a fresh install, before `migrate`
 * has run. What `fromConfig()` rejects instead is incoherent input: an unrecognised
 * mode, a column mode with no column, a foreign key with no table. SC-2's other half
 * — a table built in one mode and queried in the other — is prevented structurally,
 * since there is no second place for the migration to read the mode from.
 *
 * C4 (v0.1): the scope column is `unsignedBigInteger`, not configurable. Scope keys
 * are therefore always `int`. See docs/adr/0001-scope-and-contracts.md.
 */
final readonly class ScopeDefinition
{
    public const MODE_COLUMN = 'column';

    public const MODE_NONE = 'none';

    /**
     * Referential actions accepted for the scope foreign key.
     *
     * @var list<string>
     */
    public const ON_DELETE_ACTIONS = ['cascade', 'restrict', 'set null', 'no action'];

    /**
     * @param  self::MODE_*  $mode
     * @param  ?non-empty-string  $column  null in `none` mode
     * @param  ?non-empty-string  $foreignTable  null when no foreign key is created
     * @param  ?non-empty-string  $onDeleteAction  null when no foreign key is created
     */
    private function __construct(
        public string $mode,
        public ?string $column,
        public ?string $foreignTable,
        public ?string $onDeleteAction,
        public bool $nullable,
    ) {}

    /**
     * A scoped corpus, partitioned by `$column`. No foreign key until
     * `constrainedTo()` names a table.
     *
     * @param  non-empty-string  $column
     */
    public static function column(string $column): self
    {
        self::assertIdentifier('column', $column);

        return new self(self::MODE_COLUMN, $column, null, null, false);
    }

    /**
     * Single tenant. The column is never created and no query filters on it.
     *
     * SC-1: this is a *configured state*, not the absence of configuration. Reaching
     * it requires writing `mode => 'none'`; a missing or mistyped mode throws.
     */
    public static function none(): self
    {
        return new self(self::MODE_NONE, null, null, null, false);
    }

    /**
     * @param  non-empty-string  $table
     */
    public function constrainedTo(string $table): self
    {
        $this->assertScoped();
        self::assertIdentifier('table', $table);

        return new self($this->mode, $this->column, $table, $this->onDeleteAction ?? 'cascade', $this->nullable);
    }

    /**
     * D4: the migration emits an explicit `foreign()->references()` rather than
     * `constrained()`, so this action is stated rather than inferred from a table name.
     */
    public function onDelete(string $action): self
    {
        $this->assertScoped();

        $normalized = strtolower(trim($action));

        if (! in_array($normalized, self::ON_DELETE_ACTIONS, true)) {
            throw InvalidScopeConfiguration::invalidOnDelete($action);
        }

        if ($normalized === 'set null' && ! $this->nullable) {
            throw InvalidScopeConfiguration::setNullRequiresNullableColumn();
        }

        return new self($this->mode, $this->column, $this->foreignTable, $normalized, $this->nullable);
    }

    public function nullable(bool $nullable = true): self
    {
        $this->assertScoped();

        return new self($this->mode, $this->column, $this->foreignTable, $this->onDeleteAction, $nullable);
    }

    /**
     * Hydrate the publishable config array.
     *
     * Every rejection here is a boot-time failure by design. §4.2: a typo'd config key
     * must fail loudly, not become "search everything" — the same failure class as a
     * tenant scope going inert, which has shipped an unauthenticated cross-tenant leak
     * in this codebase's history before.
     *
     * In `none` mode the sibling keys (`column`, `table`, `foreign_key`, `on_delete`,
     * `nullable`) are inert rather than contradictory. They stay in the published file
     * as documentation of the shape a multi-tenant adopter fills in, so leaving them
     * populated while running single-tenant is not an error.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $mode = $config['mode'] ?? null;

        if (! is_string($mode)) {
            throw InvalidScopeConfiguration::unknownMode($mode);
        }

        $mode = strtolower(trim($mode));

        if ($mode === self::MODE_NONE) {
            return self::none();
        }

        if ($mode !== self::MODE_COLUMN) {
            throw InvalidScopeConfiguration::unknownMode($config['mode']);
        }

        $column = $config['column'] ?? null;

        if (! is_string($column) || trim($column) === '') {
            throw InvalidScopeConfiguration::missingColumn();
        }

        $definition = self::column(trim($column));

        $nullable = $config['nullable'] ?? false;

        if (! is_bool($nullable)) {
            throw InvalidScopeConfiguration::invalidType('nullable', 'a boolean', $nullable);
        }

        $definition = $definition->nullable($nullable);

        $foreignKey = $config['foreign_key'] ?? false;

        if (! is_bool($foreignKey)) {
            throw InvalidScopeConfiguration::invalidType('foreign_key', 'a boolean', $foreignKey);
        }

        if (! $foreignKey) {
            return $definition;
        }

        $table = $config['table'] ?? null;

        if (! is_string($table) || trim($table) === '') {
            throw InvalidScopeConfiguration::missingForeignTable();
        }

        $onDelete = $config['on_delete'] ?? 'cascade';

        if (! is_string($onDelete)) {
            throw InvalidScopeConfiguration::invalidOnDelete($onDelete);
        }

        return $definition->constrainedTo(trim($table))->onDelete($onDelete);
    }

    public function isScoped(): bool
    {
        return $this->mode === self::MODE_COLUMN;
    }

    public function hasForeignKey(): bool
    {
        return $this->foreignTable !== null;
    }

    /**
     * The scope column, for call sites that have already established the corpus is
     * scoped. Throws rather than returning null so a forgotten `isScoped()` check
     * cannot produce an unfiltered query.
     *
     * @return non-empty-string
     */
    public function requireColumn(): string
    {
        if ($this->column === null) {
            throw UnresolvableScope::notScoped();
        }

        return $this->column;
    }

    /**
     * C7: the semantic query cache key is prefixed per scope. In `none` mode this is
     * the empty string rather than a dangling separator, so the two modes never
     * produce colliding or malformed keys.
     */
    public function cacheSegment(int $scope): string
    {
        return $this->isScoped() ? $scope.':' : '';
    }

    private function assertScoped(): void
    {
        if (! $this->isScoped()) {
            throw UnresolvableScope::notScoped();
        }
    }

    private static function assertIdentifier(string $key, string $value): void
    {
        if (preg_match('/^[a-z_][a-z0-9_$]*$/i', $value) !== 1) {
            throw InvalidScopeConfiguration::invalidIdentifier($key, $value);
        }
    }
}
