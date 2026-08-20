<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Exceptions;

use Core45\ScoutPostgres\Contracts\DocumentType;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * A model or a stored `searchable_type` value maps to no registered type.
 *
 * Throwing here rather than returning null is the same discipline the scope
 * failures follow: a type that silently resolved to nothing would index no
 * documents, or read none back, and both look exactly like "this model has no
 * matches" to the caller.
 */
final class UnknownDocumentType extends RuntimeException implements ScoutPostgresException
{
    public static function forModel(Model $model): self
    {
        return new self(sprintf(
            'No document type is registered for [%s]. Add its DocumentType to '
            .'scout-postgres.types, or exclude the model from indexing.',
            $model::class,
        ));
    }

    public static function forValue(string $value): self
    {
        return new self(sprintf(
            'No document type is registered with the value [%s]. A row in search_documents carries '
            .'a type this application no longer knows about — either restore its DocumentType in '
            .'scout-postgres.types, or prune the orphaned rows.',
            $value,
        ));
    }

    public static function notADocumentType(string $class): self
    {
        return new self(sprintf(
            'scout-postgres.types lists [%s], which does not implement %s.',
            $class,
            DocumentType::class,
        ));
    }

    public static function missingClass(string $class): self
    {
        return new self(sprintf('scout-postgres.types lists [%s], which does not exist.', $class));
    }
}
