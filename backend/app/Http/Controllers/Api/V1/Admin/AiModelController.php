<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Ai\Models\AiModel;
use App\Domain\Ai\Services\PriceForecastService;
use App\Domain\User\Models\User;
use App\Http\Controllers\Controller;
use App\Services\External\AiServiceClient;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * @OA\Tag(name="Admin — AI", description="Model registry, health and manual runs")
 */
class AiModelController extends Controller
{
    public function __construct(
        private readonly AiServiceClient $ai,
        private readonly PriceForecastService $forecasts,
    ) {}

    /**
     * @OA\Get(path="/admin/ai/models", tags={"Admin — AI"}, security={{"bearerAuth":{}}},
     *   summary="Registered models and their metrics", @OA\Response(response=200, description="Models"))
     */
    public function index(): JsonResponse
    {
        $this->authorize('manageAiModels', User::class);

        return ApiResponse::success([
            'service_healthy' => $this->ai->health(),
            'models' => AiModel::orderBy('code')->orderByDesc('trained_at')->get()->map(static fn (AiModel $m) => [
                'id' => $m->getKey(),
                'code' => $m->code,
                'name' => $m->name,
                'algorithm' => $m->algorithm,
                'version' => $m->version,
                'is_active' => $m->is_active,
                'metrics' => $m->metrics,
                'training_rows' => $m->training_rows,
                'trained_at' => $m->trained_at?->toIso8601String(),
            ])->all(),
            'forecast_accuracy' => $this->forecasts->accuracySummary(),
        ]);
    }

    /**
     * @OA\Patch(path="/admin/ai/models/{model}/activate", tags={"Admin — AI"}, security={{"bearerAuth":{}}},
     *   summary="Promote a model version to active",
     *
     *   @OA\Response(response=200, description="Activated; the previous version is retired"))
     */
    public function activate(AiModel $model): JsonResponse
    {
        $this->authorize('manageAiModels', User::class);

        AiModel::where('code', $model->code)->update(['is_active' => false]);
        $model->update(['is_active' => true]);

        return ApiResponse::success(['code' => $model->code, 'version' => $model->version], 'Model activated.');
    }

    /**
     * @OA\Post(path="/admin/ai/forecast/run", tags={"Admin — AI"}, security={{"bearerAuth":{}}},
     *   summary="Trigger the weekly forecast run out of band",
     *
     *   @OA\Response(response=200, description="Forecasts generated"))
     */
    public function runForecast(Request $request): JsonResponse
    {
        $this->authorize('manageAiModels', User::class);

        $forecasts = $this->forecasts->generateWeekly(
            $request->has('week') ? Carbon::parse($request->string('week')->toString()) : null,
        );

        return ApiResponse::success([
            'generated' => count($forecasts),
            'forecasts' => collect($forecasts)->map(static fn ($f) => [
                'fuel_type_id' => $f->fuel_type_id,
                'direction' => $f->direction,
                'change_amount' => $f->change_amount,
                'confidence' => $f->confidence,
            ])->all(),
        ], 'Forecast run complete.');
    }

    /**
     * @OA\Post(path="/admin/ai/forecast/score", tags={"Admin — AI"}, security={{"bearerAuth":{}}},
     *   summary="Score published forecasts against actual DOE adjustments",
     *
     *   @OA\Response(response=200, description="Scoring summary"))
     */
    public function scoreForecasts(Request $request): JsonResponse
    {
        $this->authorize('manageAiModels', User::class);

        return ApiResponse::success($this->forecasts->scoreAgainstActuals(
            $request->has('week') ? Carbon::parse($request->string('week')->toString()) : null,
        ));
    }
}
