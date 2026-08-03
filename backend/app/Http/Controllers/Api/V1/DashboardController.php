<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Reporting\Services\DashboardService;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="Dashboards", description="Personal, fleet and executive dashboards")
 */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboards) {}

    /**
     * @OA\Get(path="/dashboard", tags={"Dashboards"}, security={{"bearerAuth":{}}},
     *   summary="Personal dashboard",
     *
     *   @OA\Response(response=200, description="Spend, savings, vehicles, forecasts and reminders"))
     */
    public function index(Request $request): JsonResponse
    {
        return ApiResponse::success($this->dashboards->forUser($request->user()));
    }

    /**
     * @OA\Get(path="/dashboard/executive", tags={"Dashboards"}, security={{"bearerAuth":{}}},
     *   summary="Platform-wide executive dashboard (admin only)",
     *
     *   @OA\Response(response=200, description="Platform metrics, growth and price analytics"),
     *   @OA\Response(response=403, description="Forbidden"))
     */
    public function executive(): JsonResponse
    {
        $this->authorize('viewExecutiveDashboard', User::class);

        return ApiResponse::success($this->dashboards->executive());
    }
}
