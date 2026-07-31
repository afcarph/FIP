<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Expense\Models\RoutePlan;
use App\Domain\Expense\Services\RouteOptimizationService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="Routes", description="Cost-optimised route planning")
 */
class RouteController extends Controller
{
    public function __construct(private readonly RouteOptimizationService $routes) {}

    /**
     * @OA\Post(path="/routes/optimize", tags={"Routes"}, security={{"bearerAuth":{}}},
     *   summary="Plan a route optimised for cost, time or fuel",
     *   @OA\RequestBody(required=true, @OA\JsonContent(
     *     required={"origin_lat","origin_lng","destination_lat","destination_lng","origin_label","destination_label"},
     *     @OA\Property(property="origin_label", type="string", example="Makati CBD"),
     *     @OA\Property(property="destination_label", type="string", example="Clark, Pampanga"),
     *     @OA\Property(property="origin_lat", type="number", format="float"),
     *     @OA\Property(property="origin_lng", type="number", format="float"),
     *     @OA\Property(property="destination_lat", type="number", format="float"),
     *     @OA\Property(property="destination_lng", type="number", format="float"),
     *     @OA\Property(property="optimize_for", type="string", enum={"cost","time","fuel","balanced"}),
     *     @OA\Property(property="vehicle_id", type="integer")
     *   )),
     *   @OA\Response(response=201, description="Ranked alternatives with fuel, toll and refuelling stop"))
     */
    public function optimize(Request $request): JsonResponse
    {
        $data = $request->validate([
            'origin_label' => ['required', 'string', 'max:180'],
            'destination_label' => ['required', 'string', 'max:180'],
            'origin_lat' => ['required', 'numeric', 'between:-90,90'],
            'origin_lng' => ['required', 'numeric', 'between:-180,180'],
            'destination_lat' => ['required', 'numeric', 'between:-90,90'],
            'destination_lng' => ['required', 'numeric', 'between:-180,180'],
            'optimize_for' => ['nullable', 'string', 'in:cost,time,fuel,balanced'],
            'vehicle_id' => ['nullable', 'integer', 'exists:vehicles,id'],
            'tank_level_pct' => ['nullable', 'numeric', 'between:0,100'],
        ]);

        $vehicle = null;

        if (! empty($data['vehicle_id'])) {
            $vehicle = Vehicle::findOrFail($data['vehicle_id']);
            $this->authorize('view', $vehicle);
        }

        $plan = $this->routes->plan($request->user(), $vehicle, $data);

        return ApiResponse::created([
            'id' => $plan->getKey(),
            'optimize_for' => $plan->optimize_for,
            'estimated_savings' => $plan->estimated_savings,
            'options' => $plan->options_payload,
        ]);
    }

    /**
     * @OA\Get(path="/routes", tags={"Routes"}, security={{"bearerAuth":{}}},
     *   summary="Recent route plans", @OA\Response(response=200, description="Plans"))
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = RoutePlan::query()
            ->where('user_id', $request->user()->getKey())
            ->latest()
            ->paginate(20);

        return ApiResponse::paginated($paginator);
    }
}
