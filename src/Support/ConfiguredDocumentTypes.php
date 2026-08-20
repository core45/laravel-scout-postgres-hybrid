<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Support;

use Core45\ScoutPostgres\Contracts\DocumentType;
use Core45\ScoutPostgres\Contracts\DocumentTypeRegistry;
use Core45\ScoutPostgres\Exceptions\UnknownDocumentType;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The default registry: types listed in `scout-postgres.types`.
 *
 * Each entry is a class name implementing {@see DocumentType}. A backed enum is
 * expanded through `::cases()`, since that is the shape the interface was
 * extracted from and the one most adopters will reach for; any other class is
 * instantiated with no arguments. An adopter whose types need constructor
 * arguments binds their own `DocumentTypeRegistry` instead — this class is a
 * default, not the contract.
 *
 * Everything is resolved once, on first use. The alternative, resolving per
 * call, would instantiate a type on every row of a result set.
 */
final class ConfiguredDocumentTypes implements DocumentTypeRegistry
{
    /**
     * @var ?list<DocumentType>
     */
    private ?array $types = null;

    /**
     * @param  list<class-string>  $classes
     */
    public function __construct(private readonly array $classes) {}

    public function forModel(Model $model): DocumentType
    {
        foreach ($this->all() as $type) {
            // `is_a` rather than a class-name equality test, so a type still
            // resolves for a model the adopter has subclassed — which Eloquent
            // itself encourages through single-table inheritance.
            if ($model instanceof ($type->modelClass())) {
                return $type;
            }
        }

        throw UnknownDocumentType::forModel($model);
    }

    public function fromValue(string $value): DocumentType
    {
        foreach ($this->all() as $type) {
            // Compared by value(), never by identity: two DocumentType instances
            // for the same type need not be the same object unless the adopter
            // happens to use an enum.
            if ($type->value() === $value) {
                return $type;
            }
        }

        throw UnknownDocumentType::forValue($value);
    }

    /**
     * @return list<DocumentType>
     */
    public function all(): array
    {
        return $this->types ??= $this->resolve();
    }

    /**
     * @return list<DocumentType>
     */
    private function resolve(): array
    {
        $types = [];

        foreach ($this->classes as $class) {
            if (! class_exists($class) && ! enum_exists($class)) {
                throw UnknownDocumentType::missingClass($class);
            }

            if (! is_subclass_of($class, DocumentType::class)) {
                throw UnknownDocumentType::notADocumentType($class);
            }

            if (enum_exists($class)) {
                /** @var list<UnitEnum&DocumentType> $cases */
                $cases = $class::cases();

                foreach ($cases as $case) {
                    $types[] = $case;
                }

                continue;
            }

            /** @var DocumentType $instance */
            $instance = new $class;
            $types[] = $instance;
        }

        return $types;
    }
}
