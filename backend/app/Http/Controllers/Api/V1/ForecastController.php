<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ai\Models\PriceForecast;
use App\Domain\Ai\Services\PriceForecastService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ForecastResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="Forecasts", description="AI weekly pump-price predictions")
 */
class ForecastController extends Controller
{
    public function __construct(private readonly PriceForecastService $forecasts) {}

    /**
     * @OA\Get(path="/forecasts", tags={"Forecasts"},
     *   summary="Latest forecast per fuel type",
     *
     *   @OA\Response(response=200, description="Forecasts with direction, confidence and drivers"))
     */
    public function index(): JsonResponse
    {
        return ApiResponse::success(
            ForecastResource::collection(collect($this->forecasts->latest()))->resolve(),
        );
    }

    /**
     * @OA\Get(path="/forecasts/history", tags={"Forecasts"},
     *   summary="Past forecasts scored against actual DOE adjustments",
     *
     *   @OA\Response(response=200, description="Forecast vs actual"))
     */
    public function history(Request $request): JsonResponse
    {
        $request->validate([
            'fuel_type_id' => ['nullable', 'integer', 'exists:fuel_types,id'],
            'weeks' => ['nullable', 'integer', 'min:1', 'max:104'],
        ]);

        $forecasts = PriceForecast::query()
            ->when($request->has('fuel_type_id'), fn ($q) => $q->where('fuel_type_id', $request->integer('fuel_type_id')))
            ->whereNotNull('actual_change')
            ->where('forecast_for', '>=', now()->subWeeks((int) $request->integer('weeks', 12))->startOfWeek())
            ->with('fuelType')
            ->orderByDesc('forecast_for')
            ->get();

        return ApiResponse::success(ForecastResource::collection($forecasts)->resolve());
    }

    /**
     * @OA\Get(path="/forecasts/accuracy", tags={"Forecasts"},
     *   summary="Published model accuracy over the trailing period",
     *
     *   @OA\Response(response=200, description="MAE and direction accuracy"))
     */
    public function accuracy(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $this->forecasts->accuracySummary((int) $request->integer('weeks', 26)),
        );
    }
}
