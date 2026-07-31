<?php

declare(strict_types=1);

namespace App\Support\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Translates every uncaught throwable into the platform's single error shape:
 *
 *   { "success": false, "error": { "code": "...", "message": "...",
 *     "details": {...} }, "meta": { "request_id": "..." } }
 *
 * Internal messages are never leaked outside local/testing environments.
 */
final class ApiExceptionRenderer
{
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        [$status, $code, $message, $details] = self::classify($e);

        $payload = [
            'success' => false,
            'error' => array_filter([
                'code' => $code,
                'message' => $message,
                'details' => $details ?: null,
            ], static fn ($v) => $v !== null),
            'meta' => [
                'request_id' => $request->attributes->get('request_id', $request->header('X-Request-Id')),
                'timestamp' => now()->toIso8601String(),
            ],
        ];

        if ($status >= 500 && config('app.debug')) {
            $payload['error']['debug'] = [
                'exception' => $e::class,
                'file' => $e->getFile().':'.$e->getLine(),
                'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 15),
            ];
        }

        return new JsonResponse($payload, $status);
    }

    /** @return array{int, string, string, array} */
    private static function classify(Throwable $e): array
    {
        return match (true) {
            $e instanceof ValidationException => [
                422, 'validation_failed', 'The given data was invalid.', $e->errors(),
            ],
            $e instanceof DomainException => [
                $e->status(), $e->errorCode(), $e->getMessage(), $e->context(),
            ],
            $e instanceof AuthenticationException => [
                401, 'unauthenticated', 'Authentication is required to access this resource.', [],
            ],
            $e instanceof AuthorizationException => [
                403, 'forbidden', $e->getMessage() ?: 'This action is unauthorized.', [],
            ],
            $e instanceof ModelNotFoundException => [
                404, 'not_found', 'The requested resource does not exist.', [],
            ],
            $e instanceof NotFoundHttpException => [
                404, 'not_found', 'The requested endpoint does not exist.', [],
            ],
            $e instanceof TooManyRequestsHttpException => [
                429, 'rate_limited', 'Too many requests. Please slow down.', [],
            ],
            $e instanceof HttpExceptionInterface => [
                $e->getStatusCode(), 'http_error', $e->getMessage() ?: 'Request failed.', [],
            ],
            default => [
                500,
                'server_error',
                config('app.debug') ? $e->getMessage() : 'An unexpected error occurred.',
                [],
            ],
        };
    }
}
