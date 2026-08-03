<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Pricing\Models\FuelType;
use App\Domain\Pricing\Repositories\PriceRepository;
use App\Domain\Pricing\Services\PriceService;
use App\Domain\Station\Models\GasStation;
use App\Domain\Station\Repositories\GasStationRepository;
use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\UpdateStationPriceRequest;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="Prices", description="Live prices, history, comparison and heat map")
 */
class PriceController extends Controller
{
    public function __construct(
        private readonly PriceService $priceService,
        private readonly PriceRepository $prices,
        private readonly GasStationRepository $stations,
    ) {}

    /**
     * @OA\Get(path="/prices/fuel-types", tags={"Prices"}, summary="Fuel types reference",
     *
     *   @OA\Response(response=200, description="Active fuel types"))
     */
    public function fuelTypes(): JsonResponse
    {
        return ApiResponse::success(
            FuelType::active()->get(['id', 'code', 'name', 'category', 'octane', 'unit', 'color_hex'])->all(),
        );
    }

    /**
     * @OA\Get(path="/prices/comparison", tags={"Prices"},
     *   summary="Min / average / max price per fuel type",
     *
     *   @OA\Parameter(name="city_id", in="query", @OA\Schema(type="integer")),
     *
     *   @OA\Response(response=200, description="Comparison matrix"))
     */
    public function comparison(Request $request): JsonResponse
    {
        $cityId = $request->has('city_id') ? (int) $request->integer('city_id') : null;

        return ApiResponse::success($this->priceService->comparison($cityId));
    }

    /**
     * @OA\Get(path="/prices/trend", tags={"Prices"}, summary="National daily average series",
     *
     *   @OA\Parameter(name="fuel_type_id", in="query", required=true, @OA\Schema(type="integer")),
     *   @OA\Parameter(name="days", in="query", @OA\Schema(type="integer", default=90, maximum=730)),
     *
     *   @OA\Response(response=200, description="Time series"))
     */
    public function trend(Request $request): JsonResponse
    {
        $request->validate([
            'fuel_type_id' => ['required', 'integer', 'exists:fuel_types,id'],
            'days' => ['nullable', 'integer', 'min:7', 'max:730'],
        ]);

        return ApiResponse::success($this->priceService->trend(
            (int) $request->integer('fuel_type_id'),
            (int) $request->integer('days', 90),
        ));
    }

    /**
     * @OA\Get(path="/prices/advisories", tags={"Prices"},
     *   summary="Weekly DOE adjustment history",
     *
     *   @OA\Parameter(name="fuel_type_id", in="query", required=true, @OA\Schema(type="integer")),
     *   @OA\Parameter(name="weeks", in="query", @OA\Schema(type="integer", default=12)),
     *
     *   @OA\Response(response=200, description="Advisory history"))
     */
    public function advisories(Request $request): JsonResponse
    {
        $request->validate([
            'fuel_type_id' => ['required', 'integer', 'exists:fuel_types,id'],
            'weeks' => ['nullable', 'integer', 'min:1', 'max:104'],
            'region_id' => ['nullable', 'integer', 'exists:regions,id'],
        ]);

        return ApiResponse::success($this->prices->advisoryHistory(
            (int) $request->integer('fuel_type_id'),
            (int) $request->integer('weeks', 12),
            $request->has('region_id') ? (int) $request->integer('region_id') : null,
        ));
    }

    /**
     * @OA\Get(path="/prices/heat-map", tags={"Prices"},
     *   summary="City-level average prices for map shading",
     *
     *   @OA\Response(response=200, description="Heat map cells"))
     */
    public function heatMap(Request $request): JsonResponse
    {
        $fuelTypeId = $request->has('fuel_type_id') ? (int) $request->integer('fuel_type_id') : null;
        $regionId = $request->has('region_id') ? (int) $request->integer('region_id') : null;

        return ApiResponse::success($this->priceService->heatMapPayload(
            fn () => $this->stations->heatMap($fuelTypeId, $regionId),
            $fuelTypeId,
            $regionId,
        ));
    }

    /**
     * @OA\Get(path="/prices/regional-movement", tags={"Prices"},
     *   summary="Week-on-week price movement by region",
     *
     *   @OA\Response(response=200, description="Regional movement"))
     */
    public function regionalMovement(Request $request): JsonResponse
    {
        $request->validate(['fuel_type_id' => ['required', 'integer', 'exists:fuel_types,id']]);

        return ApiResponse::success($this->prices->regionalMovement((int) $request->integer('fuel_type_id')));
    }

    /**
     * @OA\Get(path="/stations/{station}/prices/history", tags={"Prices"},
     *   summary="Price history for one station and fuel type",
     *
     *   @OA\Response(response=200, description="Time series"))
     */
    public function stationHistory(Request $request, GasStation $station): JsonResponse
    {
        $request->validate([
            'fuel_type_id' => ['required', 'integer', 'exists:fuel_types,id'],
            'days' => ['nullable', 'integer', 'min:7', 'max:365'],
        ]);

        return ApiResponse::success($this->prices->stationHistory(
            $station->getKey(),
            (int) $request->integer('fuel_type_id'),
            (int) $request->integer('days', 90),
        ));
    }

    /**
     * @OA\Put(path="/stations/{station}/prices", tags={"Prices"}, security={{"bearerAuth":{}}},
     *   summary="Operator updates a station's board price",
     *
     *   @OA\Response(response=200, description="Price recorded"),
     *   @OA\Response(response=403, description="Not your station"))
     */
    public function update(UpdateStationPriceRequest $request, GasStation $station): JsonResponse
    {
        $this->authorize('updatePrices', $station);

        $updated = [];

        foreach ($request->validated()['prices'] as $entry) {
            $updated[] = $this->priceService->recordPrice(
                station: $station,
                fuelTypeId: (int) $entry['fuel_type_id'],
                price: (float) $entry['price'],
                source: 'operator',
                confidence: 1.0,
                reportedBy: $request->user()->getKey(),
            );
        }

        return ApiResponse::success(
            collect($updated)->map(static fn ($p) => [
                'fuel_type_id' => $p->fuel_type_id,
                'price' => (float) $p->price,
                'previous_price' => $p->previous_price !== null ? (float) $p->previous_price : null,
                'change_amount' => (float) $p->change_amount,
                'effective_at' => $p->effective_at->toIso8601String(),
            ])->all(),
            'Prices updated.',
        );
    }
}
