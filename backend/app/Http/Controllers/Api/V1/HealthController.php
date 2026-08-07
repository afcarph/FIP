<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Doe\Services\SystemHealth;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * Readiness, and the operator's view of the platform.
 *
 * `/api/health` outside the version prefix stays a liveness probe: it answers
 * "is this process up" and nothing more, because a liveness check that touches
 * the database restarts healthy containers whenever the database hiccups.
 * This one answers the different question of whether the instance is fit to
 * serve, and is allowed to be slower and to fail.
 */
class HealthController extends Controller
{
    public function __construct(private readonly SystemHealth $health) {}

    /**
     * Readiness: database, scheduler, disk and archive storage.
     *
     * Returns 503 when a check is down, so a load balancer can act on it
     * without parsing the body. Degraded stays 200 — a nearly full disk is
     * worth an alert and not worth taking the instance out of rotation.
     */
    public function show(): JsonResponse
    {
        $result = $this->health->readiness();

        return ApiResponse::success(
            $result,
            status: $result['status'] === 'down' ? 503 : 200,
        );
    }

    /**
     * Everything the system dashboard shows.
     *
     * Behind authentication: it reports filesystem paths, disk capacity and
     * database driver, which are useful to an operator and to nobody else.
     */
    public function system(): JsonResponse
    {
        return ApiResponse::success($this->health->system());
    }
}
