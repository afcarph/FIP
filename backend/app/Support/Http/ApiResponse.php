<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Single envelope for every successful API response so that clients — web,
 * Flutter and third-party integrations — can parse one shape everywhere.
 */
final class ApiResponse
{
    /**
     * Keep the decimal point on whole floats. Without this, json_encode() emits
     * the price 56.0 as `56`, and a strictly typed client — Flutter's
     * `as double`, a generated TypeScript model — rejects the integer. The flag
     * has to be supplied at construction: setting it afterwards would re-encode
     * data that has already lost its float-ness.
     */
    private const ENCODING_OPTIONS = JSON_PRESERVE_ZERO_FRACTION;

    public static function success(mixed $data = null, ?string $message = null, int $status = 200, array $meta = []): JsonResponse
    {
        return new JsonResponse(array_filter([
            'success' => true,
            'message' => $message,
            'data' => $data instanceof JsonResource ? $data->resolve() : $data,
            'meta' => self::meta($meta) ?: null,
        ], static fn ($v) => $v !== null), $status, [], self::ENCODING_OPTIONS);
    }

    public static function created(mixed $data = null, ?string $message = 'Created successfully.'): JsonResponse
    {
        return self::success($data, $message, 201);
    }

    public static function noContent(): JsonResponse
    {
        return new JsonResponse(null, 204, [], self::ENCODING_OPTIONS);
    }

    /** Wrap a paginator, hoisting pagination details into `meta`. */
    public static function paginated(LengthAwarePaginator $paginator, ?ResourceCollection $collection = null, array $meta = []): JsonResponse
    {
        $items = $collection !== null
            ? $collection->resolve()
            : $paginator->items();

        return new JsonResponse([
            'success' => true,
            'data' => $items,
            'meta' => self::meta(array_merge($meta, [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'last_page' => $paginator->lastPage(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            ])),
        ], 200, [], self::ENCODING_OPTIONS);
    }

    public static function error(string $code, string $message, int $status = 400, array $details = []): JsonResponse
    {
        return new JsonResponse([
            'success' => false,
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'details' => $details ?: null,
            ], static fn ($v) => $v !== null),
            'meta' => self::meta(),
        ], $status, [], self::ENCODING_OPTIONS);
    }

    private static function meta(array $extra = []): array
    {
        return array_merge([
            'request_id' => request()?->attributes->get('request_id'),
            'timestamp' => now()->toIso8601String(),
        ], $extra);
    }
}
