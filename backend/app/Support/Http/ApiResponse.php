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
    public static function success(mixed $data = null, ?string $message = null, int $status = 200, array $meta = []): JsonResponse
    {
        return new JsonResponse(array_filter([
            'success' => true,
            'message' => $message,
            'data' => $data instanceof JsonResource ? $data->resolve() : $data,
            'meta' => self::meta($meta) ?: null,
        ], static fn ($v) => $v !== null), $status);
    }

    public static function created(mixed $data = null, ?string $message = 'Created successfully.'): JsonResponse
    {
        return self::success($data, $message, 201);
    }

    public static function noContent(): JsonResponse
    {
        return new JsonResponse(null, 204);
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
        ]);
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
        ], $status);
    }

    private static function meta(array $extra = []): array
    {
        return array_merge([
            'request_id' => request()?->attributes->get('request_id'),
            'timestamp' => now()->toIso8601String(),
        ], $extra);
    }
}
