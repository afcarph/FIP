<?php

declare(strict_types=1);

namespace App\Support\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A JSON cast that preserves float-ness.
 *
 * The stock `array` cast encodes through json_encode() with no flags, so a
 * whole float such as 85.0 is written as `85` and reads back as an int. For
 * payloads that carry measurements — litres, prices, scores, confidence — that
 * silently changes the type between write and read, and again between the API
 * and its typed clients. JSON_PRESERVE_ZERO_FRACTION keeps the decimal point.
 *
 * @implements CastsAttributes<array<mixed>, array<mixed>>
 */
final class NumericJson implements CastsAttributes
{
    /** @return array<mixed> */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if ($value === null || $value === '') {
            return [];
        }

        return json_decode((string) $value, true) ?? [];
    }

    /** @return array<string, string> */
    public function set(Model $model, string $key, mixed $value, array $attributes): array
    {
        return [$key => json_encode(
            $value ?? [],
            JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        )];
    }
}
