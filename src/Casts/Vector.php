<?php

declare(strict_types=1);

namespace Core45\ScoutPostgres\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Casts a pgvector `vector` column to and from a plain list of floats.
 *
 * Laravel 13 ships the `vector` column type and the query-builder helpers but
 * no Eloquent cast, so the raw attribute arrives as pgvector's text form —
 * `[0.1,0.2,…]`. That happens to be identical to `json_encode()` of the array,
 * which is exactly how the framework binds vectors itself
 * (Query\Builder::whereVectorDistanceLessThan), so the conversion is symmetric
 * in both directions.
 *
 * @implements CastsAttributes<list<float>|null, list<float>|null>
 */
class Vector implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<float>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_array($value)) {
            return array_map(floatval(...), array_values($value));
        }

        $decoded = json_decode((string) $value, true);

        if (! is_array($decoded)) {
            return null;
        }

        return array_map(floatval(...), array_values($decoded));
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            return $value;
        }

        if (! is_array($value)) {
            return null;
        }

        return json_encode(
            array_map(floatval(...), array_values($value)),
            JSON_THROW_ON_ERROR,
        );
    }
}
