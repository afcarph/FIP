<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Assigns a correlation ID to every request and records timing.
 *
 * The ID is echoed back in `X-Request-Id` and embedded in error payloads, so
 * a user-reported failure can be traced through application logs, the audit
 * trail and the AI service in one search. Write-path and error responses are
 * persisted; successful reads are sampled to keep the table small.
 */
class LogApiRequest
{
    private const SAMPLE_RATE = 0.05;

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-Id') ?: (string) Str::uuid();
        $request->attributes->set('request_id', $requestId);

        $startedAt = microtime(true);

        /** @var Response $response */
        $response = $next($request);

        $durationMs = (int) round((microtime(true) - $startedAt) * 1000);

        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('X-Response-Time', $durationMs.'ms');

        if ($this->shouldPersist($request, $response)) {
            $this->persist($request, $response, $requestId, $durationMs);
        }

        return $response;
    }

    private function shouldPersist(Request $request, Response $response): bool
    {
        if ($response->getStatusCode() >= 400) {
            return true;
        }

        if (! $request->isMethod('GET')) {
            return true;
        }

        return mt_rand() / mt_getrandmax() < self::SAMPLE_RATE;
    }

    private function persist(Request $request, Response $response, string $requestId, int $durationMs): void
    {
        try {
            DB::table('api_request_logs')->insert([
                'user_id' => auth()->id(),
                'method' => $request->method(),
                'path' => mb_substr($request->path(), 0, 255),
                'status_code' => $response->getStatusCode(),
                'duration_ms' => $durationMs,
                'ip_address' => $request->ip() ? inet_pton($request->ip()) : null,
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 255),
                'request_id' => $requestId,
                'created_at' => now(),
            ]);
        } catch (\Throwable) {
            // Telemetry must never break the response.
        }
    }
}
