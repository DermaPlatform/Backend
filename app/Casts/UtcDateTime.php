<?php

namespace App\Casts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Stores a datetime as the UTC instant it represents.
 *
 * Laravel's own `datetime` cast writes whatever wall clock digits the value
 * carries, so a Carbon built in the clinic timezone lands in the column as
 * those literal digits and quietly becomes a different moment in time. Every
 * comparison after that is off by the UTC offset, and the size of the error
 * changes with daylight saving, which makes it look intermittent.
 *
 * Apply this to any column that can be written from clinic wall clock input.
 *
 * @implements CastsAttributes<CarbonImmutable, mixed>
 */
class UtcDateTime implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse($value, 'UTC');
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse($value)->utc()->format('Y-m-d H:i:s');
    }
}
