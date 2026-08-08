<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Doe\Services\DoeStationReference;
use App\Domain\Station\Models\GasStation;
use App\Domain\Station\Repositories\GasStationRepository;
use App\Http\Controllers\Controller;
use App\Http\Requests\Station\NearbyStationRequest;
use App\Http\Requests\Station\StoreStationRequest;
use App\Http\Resources\StationResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="Stations", description="Gas station directory, proximity search and ratings")
 */
class StationController extends Controller
{
    public function __construct(
        private readonly GasStationRepository $stations,
        private readonly DoeStationReference $doe,
    ) {}

    /**
     * @OA\Get(path="/stations", tags={"Stations"}, summary="Browse the station directory",
     *
     *   @OA\Parameter(name="search", in="query", @OA\Schema(type="string")),
     *   @OA\Parameter(name="brand_id", in="query", @OA\Schema(type="integer")),
     *   @OA\Parameter(name="city_id", in="query", @OA\Schema(type="integer")),
     *   @OA\Parameter(name="fuel_type_id", in="query", @OA\Schema(type="integer")),
     *   @OA\Parameter(name="has_ev_charging", in="query", @OA\Schema(type="boolean")),
     *   @OA\Parameter(name="per_page", in="query", @OA\Schema(type="integer", maximum=100)),
     *
     *   @OA\Response(response=200, description="Paginated stations"))
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->stations->paginateDirectory(
            (int) $request->integer('per_page', 15),
            $request->only(['search', 'brand_id', 'city_id', 'status', 'is_24_hours', 'has_ev_charging', 'fuel_type_id', 'amenities', 'payment_methods', 'sort']),
        );

        return ApiResponse::paginated($paginator, StationResource::collection($paginator));
    }

    /**
     * @OA\Get(path="/stations/nearby", tags={"Stations"},
     *   summary="Stations within a radius, nearest first",
     *
     *   @OA\Parameter(name="latitude", in="query", required=true, @OA\Schema(type="number", format="float")),
     *   @OA\Parameter(name="longitude", in="query", required=true, @OA\Schema(type="number", format="float")),
     *   @OA\Parameter(name="radius_km", in="query", @OA\Schema(type="number", default=5, maximum=50)),
     *   @OA\Parameter(name="fuel_type_id", in="query", @OA\Schema(type="integer")),
     *
     *   @OA\Response(response=200, description="Nearby stations with distance and live prices"))
     */
    public function nearby(NearbyStationRequest $request): JsonResponse
    {
        $stations = $this->stations->nearby(
            $request->float('latitude'),
            $request->float('longitude'),
            (float) $request->input('radius_km', config('fip.pricing.default_radius_km')),
            $request->has('fuel_type_id') ? (int) $request->integer('fuel_type_id') : null,
            (int) $request->integer('limit', 25),
        );

        return ApiResponse::success(StationResource::collection($stations)->resolve(), null, 200, [
            'count' => $stations->count(),
        ]);
    }

    /**
     * @OA\Get(path="/stations/cheapest", tags={"Stations"},
     *   summary="Cheapest stations nearby for one fuel type",
     *
     *   @OA\Parameter(name="fuel_type_id", in="query", required=true, @OA\Schema(type="integer")),
     *
     *   @OA\Response(response=200, description="Ranked by price then distance"))
     */
    public function cheapest(NearbyStationRequest $request): JsonResponse
    {
        $request->validate(['fuel_type_id' => ['required', 'integer', 'exists:fuel_types,id']]);

        $stations = $this->stations->cheapestNearby(
            $request->float('latitude'),
            $request->float('longitude'),
            (int) $request->integer('fuel_type_id'),
            (float) $request->input('radius_km', config('fip.pricing.default_radius_km')),
            (int) $request->integer('limit', 10),
        );

        return ApiResponse::success($stations->map(static fn (GasStation $s) => [
            'id' => $s->getKey(),
            'name' => $s->name,
            'brand' => $s->brand?->name,
            'address' => $s->address_line,
            'latitude' => $s->latitude,
            'longitude' => $s->longitude,
            'price' => (float) ($s->current_price ?? 0),
            'price_effective_at' => $s->price_effective_at,
            'distance_km' => round(((float) ($s->distance_m ?? 0)) / 1000, 2),
        ])->all());
    }

    /**
     * @OA\Get(path="/stations/{slug}", tags={"Stations"}, summary="Station detail",
     *
     *   @OA\Parameter(name="slug", in="path", required=true, @OA\Schema(type="string")),
     *
     *   @OA\Response(response=200, description="Station"),
     *   @OA\Response(response=404, description="Not found"))
     */
    public function show(string $slug): JsonResponse
    {
        $station = $this->stations->findBySlug($slug);

        abort_if($station === null, 404);

        // Attached to the detail view only. The directory listing does not
        // carry it: resolving a reference per row would read the week's
        // prices for every station on the page, and the card only needs it
        // once the user has opened one.
        $station->doe_reference = $this->doe->for($station);

        return ApiResponse::success(new StationResource($station));
    }

    /**
     * @OA\Post(path="/stations", tags={"Stations"}, security={{"bearerAuth":{}}},
     *   summary="Create a station (admin or station operator)",
     *
     *   @OA\Response(response=201, description="Created"),
     *   @OA\Response(response=403, description="Forbidden"))
     */
    public function store(StoreStationRequest $request): JsonResponse
    {
        $this->authorize('create', GasStation::class);

        $station = $this->stations->create($request->validated() + [
            'created_by' => $request->user()->getKey(),
            'status' => $request->user()->isPlatformAdministrator() ? 'active' : 'pending_review',
        ]);

        if ($request->filled('amenities')) {
            $station->amenities()->sync($request->input('amenities'));
        }

        if ($request->filled('payment_methods')) {
            $station->paymentMethods()->sync($request->input('payment_methods'));
        }

        return ApiResponse::created(new StationResource($station->load('brand', 'city')));
    }

    /**
     * @OA\Put(path="/stations/{station}", tags={"Stations"}, security={{"bearerAuth":{}}},
     *   summary="Update a station", @OA\Response(response=200, description="Updated"))
     */
    public function update(StoreStationRequest $request, GasStation $station): JsonResponse
    {
        $this->authorize('update', $station);

        $this->stations->update($station, $request->validated());

        if ($request->has('amenities')) {
            $station->amenities()->sync($request->input('amenities', []));
        }

        if ($request->has('payment_methods')) {
            $station->paymentMethods()->sync($request->input('payment_methods', []));
        }

        return ApiResponse::success(new StationResource($station->fresh(['brand', 'city', 'amenities', 'paymentMethods'])));
    }

    /**
     * @OA\Delete(path="/stations/{station}", tags={"Stations"}, security={{"bearerAuth":{}}},
     *   summary="Soft-delete a station", @OA\Response(response=204, description="Deleted"))
     */
    public function destroy(GasStation $station): JsonResponse
    {
        $this->authorize('delete', $station);

        $this->stations->delete($station);

        return ApiResponse::noContent();
    }

    /**
     * @OA\Post(path="/stations/{station}/rate", tags={"Stations"}, security={{"bearerAuth":{}}},
     *   summary="Rate a station 1–5", @OA\Response(response=200, description="Rating saved"))
     */
    public function rate(Request $request, GasStation $station): JsonResponse
    {
        $data = $request->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'comment' => ['nullable', 'string', 'max:500'],
        ]);

        $station->ratings()->updateOrCreate(
            ['user_id' => $request->user()->getKey()],
            $data,
        );

        return ApiResponse::success([
            'rating_avg' => $station->fresh()->rating_avg,
            'rating_count' => $station->fresh()->rating_count,
        ], 'Thanks for the feedback.');
    }
}
