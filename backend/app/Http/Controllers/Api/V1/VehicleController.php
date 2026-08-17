<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ai\Models\FraudAlert;
use App\Domain\Expense\Models\Trip;
use App\Domain\Fleet\Services\FuelLevelService;
use App\Domain\Maintenance\Services\MaintenanceService;
use App\Domain\User\Models\UserDevice;
use App\Domain\User\Services\SubscriptionLimitService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Repositories\VehicleRepository;
use App\Http\Controllers\Controller;
use App\Http\Requests\Vehicle\StoreFuelReadingRequest;
use App\Http\Requests\Vehicle\StoreVehicleRequest;
use App\Http\Requests\Vehicle\UpdateVehicleRequest;
use App\Http\Resources\FuelReadingResource;
use App\Http\Resources\VehicleResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * @OA\Tag(name="Vehicles", description="Vehicle registry, odometer and documents")
 */
class VehicleController extends Controller
{
    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly MaintenanceService $maintenance,
        private readonly FuelLevelService $fuelLevels,
        private readonly SubscriptionLimitService $limits,
    ) {}

    /**
     * @OA\Get(path="/vehicles", tags={"Vehicles"}, security={{"bearerAuth":{}}},
     *   summary="List vehicles visible to the caller",
     *
     *   @OA\Response(response=200, description="Paginated vehicles"))
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = $this->vehicles->paginateForUser(
            $request->user(),
            (int) $request->integer('per_page', 15),
            $request->only(['search', 'fleet_id', 'status', 'vehicle_type', 'fuel_type_id', 'sort']),
        );

        return ApiResponse::paginated($paginator, VehicleResource::collection($paginator));
    }

    /**
     * @OA\Post(path="/vehicles", tags={"Vehicles"}, security={{"bearerAuth":{}}},
     *   summary="Register a vehicle", @OA\Response(response=201, description="Created"))
     */
    public function store(StoreVehicleRequest $request): JsonResponse
    {
        $this->authorize('create', Vehicle::class);

        // Capacity, after authorisation: a caller who may not create vehicles
        // should be told that, not told their plan is full.
        $this->limits->assertCanAdd($request->user(), SubscriptionLimitService::VEHICLES);

        $data = $request->validated();

        // Private users own their vehicles; company staff register against the tenant.
        $data += $request->user()->company_id !== null
            ? ['company_id' => $request->user()->company_id]
            : ['owner_id' => $request->user()->getKey()];

        $vehicle = $this->vehicles->create($data);

        $this->maintenance->bootstrapSchedule($vehicle);

        return ApiResponse::created(new VehicleResource($vehicle->load('make', 'model', 'fuelType')));
    }

    /**
     * @OA\Get(path="/vehicles/{vehicle}", tags={"Vehicles"}, security={{"bearerAuth":{}}},
     *   summary="Vehicle detail", @OA\Response(response=200, description="Vehicle"))
     */
    public function show(Vehicle $vehicle): JsonResponse
    {
        $this->authorize('view', $vehicle);

        return ApiResponse::success(new VehicleResource(
            $vehicle->load('make', 'model', 'fuelType', 'fleet', 'documents', 'currentAssignment.driver', 'maintenanceSchedules.type'),
        ));
    }

    /**
     * @OA\Put(path="/vehicles/{vehicle}", tags={"Vehicles"}, security={{"bearerAuth":{}}},
     *   summary="Update a vehicle", @OA\Response(response=200, description="Updated"))
     */
    public function update(UpdateVehicleRequest $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorize('update', $vehicle);

        $this->vehicles->update($vehicle, $request->validated());
        $this->maintenance->refreshStatuses($vehicle);

        return ApiResponse::success(new VehicleResource($vehicle->fresh(['make', 'model', 'fuelType'])));
    }

    /**
     * @OA\Delete(path="/vehicles/{vehicle}", tags={"Vehicles"}, security={{"bearerAuth":{}}},
     *   summary="Soft-delete a vehicle", @OA\Response(response=204, description="Deleted"))
     */
    public function destroy(Vehicle $vehicle): JsonResponse
    {
        $this->authorize('delete', $vehicle);

        $this->vehicles->delete($vehicle);

        return ApiResponse::noContent();
    }

    /**
     * @OA\Post(path="/vehicles/{vehicle}/odometer", tags={"Vehicles"}, security={{"bearerAuth":{}}},
     *   summary="Record an odometer reading",
     *
     *   @OA\Response(response=201, description="Reading recorded"),
     *   @OA\Response(response=422, description="Reading lower than the last one"))
     */
    public function recordOdometer(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorize('update', $vehicle);

        $data = $request->validate([
            'reading' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'recorded_at' => ['nullable', 'date', 'before_or_equal:now'],
            'photo_path' => ['nullable', 'string', 'max:255'],
        ]);

        if ((float) $data['reading'] < $vehicle->current_odometer) {
            return ApiResponse::error(
                'odometer_rollback',
                sprintf('Reading %.2f km is below the recorded %.2f km.', (float) $data['reading'], $vehicle->current_odometer),
                422,
            );
        }

        $reading = $vehicle->odometerReadings()->create([
            'reading' => $data['reading'],
            'source' => 'manual',
            'recorded_at' => $data['recorded_at'] ?? now(),
            'recorded_by' => $request->user()->getKey(),
            'photo_path' => $data['photo_path'] ?? null,
        ]);

        $vehicle->forceFill(['current_odometer' => $data['reading']])->save();
        $this->maintenance->refreshStatuses($vehicle);

        return ApiResponse::created([
            'reading' => (float) $reading->reading,
            'recorded_at' => $reading->recorded_at->toIso8601String(),
            'current_odometer' => $vehicle->current_odometer,
        ]);
    }

    /**
     * @OA\Post(path="/vehicles/{vehicle}/fuel-readings", tags={"Vehicles"}, security={{"bearerAuth":{}}},
     *   summary="Record a fuel level reading",
     *
     *   @OA\Response(response=201, description="Reading recorded"),
     *   @OA\Response(response=422, description="Level out of range, or dated in the future"))
     */
    public function recordFuelReading(StoreFuelReadingRequest $request, Vehicle $vehicle): JsonResponse
    {
        // Same gate as an odometer reading: recording what a vehicle is doing
        // is an update to it, which an assigned driver may perform.
        $this->authorize('update', $vehicle);

        $reading = $this->fuelLevels->record($vehicle, $request->validated() + [
            'recorded_by' => $request->user()->getKey(),
        ]);

        $vehicle->refresh();

        return ApiResponse::created([
            'id' => $reading->getKey(),
            'fuel_pct' => $reading->fuel_pct,
            'fuel_litres' => $reading->fuel_litres,
            'delta_pct' => $reading->delta_pct,
            'source' => $reading->source,
            'recorded_at' => $reading->recorded_at->toIso8601String(),
            'vehicle' => [
                'current_fuel_pct' => $vehicle->current_fuel_pct,
                'current_fuel_litres' => $vehicle->current_fuel_litres,
                'fuel_level_at' => $vehicle->fuel_level_at?->toIso8601String(),
                'fuel_status' => $this->fuelLevels->statusFor($vehicle->current_fuel_pct),
            ],
        ]);
    }

    /**
     * @OA\Get(path="/vehicles/{vehicle}/fuel-readings", tags={"Vehicles"}, security={{"bearerAuth":{}}},
     *   summary="Fuel level history",
     *
     *   @OA\Parameter(name="from", in="query", @OA\Schema(type="string", format="date")),
     *   @OA\Parameter(name="to", in="query", @OA\Schema(type="string", format="date")),
     *   @OA\Parameter(name="source", in="query", @OA\Schema(type="string")),
     *
     *   @OA\Response(response=200, description="Paginated readings, newest first"))
     */
    /**
     * @OA\Get(path="/vehicles/{vehicle}/alerts", tags={"Vehicles"}, security={{"bearerAuth":{}}},
     *   summary="Fuel alerts raised against one vehicle",
     *
     *   @OA\Response(response=200, description="Alerts"),
     *   @OA\Response(response=403, description="Not your vehicle, and no fraud.view"))
     */
    /**
     * @OA\Get(path="/vehicles/{vehicle}/location", tags={"Vehicles"}, security={{"bearerAuth":{}}},
     *   summary="Latest known position of one vehicle",
     *
     *   @OA\Response(response=200, description="Position, or null when none is known"),
     *   @OA\Response(response=403, description="Not your vehicle, and no devices.location.view"))
     */
    public function location(Vehicle $vehicle): JsonResponse
    {
        $this->authorize('viewLatestLocation', $vehicle);

        // The device cache, the same source /fleet/locations reads, so the two
        // endpoints cannot disagree about where a vehicle is. Scoped to one
        // vehicle by construction rather than by filtering a fleet-wide list.
        $device = UserDevice::query()
            ->active()
            ->where('vehicle_id', $vehicle->getKey())
            ->whereNotNull('last_location_at')
            ->latest('last_location_at')
            ->first();

        return ApiResponse::success($device === null ? null : [
            'vehicle_id' => $vehicle->getKey(),
            'latitude' => $device->last_latitude,
            'longitude' => $device->last_longitude,
            'recorded_at' => $device->last_location_at?->toIso8601String(),
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
        ]);
    }

    public function alerts(Request $request, Vehicle $vehicle): JsonResponse
    {
        // Scoped to one vehicle by construction. A driver reaches their own
        // assigned vehicle without holding any fraud permission; a manager
        // reaches any vehicle in their tenant because they hold fraud.view.
        // Neither can read another company's alerts.
        $this->authorize('viewAlerts', $vehicle);

        $paginator = FraudAlert::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->when(
                $request->has('status'),
                fn ($q) => $q->where('status', $request->string('status')->toString()),
                fn ($q) => $q->unresolved(),
            )
            ->with(['purchase', 'reading'])
            ->latest('detected_at')
            ->paginate(min((int) $request->integer('per_page', 20), 50));

        return ApiResponse::paginated($paginator);
    }

    public function fuelReadings(Request $request, Vehicle $vehicle): JsonResponse
    {
        // Reading the history is a read on the vehicle, not a write, so this
        // is the `view` gate rather than the `update` one that recording uses.
        $this->authorize('view', $vehicle);

        $paginator = $vehicle->fuelReadings()
            // In the application's timezone before the day is taken: the
            // column is a local wall clock, and a client sending a UTC instant
            // would otherwise ask for the boundaries of a different day.
            ->when($request->has('from'), fn ($q) => $q->where(
                'recorded_at', '>=', Carbon::parse($request->string('from')->toString())
                    ->setTimezone(config('app.timezone'))->startOfDay(),
            ))
            ->when($request->has('to'), fn ($q) => $q->where(
                'recorded_at', '<=', Carbon::parse($request->string('to')->toString())
                    ->setTimezone(config('app.timezone'))->endOfDay(),
            ))
            ->when($request->has('source'), fn ($q) => $q->where('source', $request->string('source')->toString()))
            // Newest first, matching every other history endpoint. A chart
            // wants the reverse, but paginating oldest-first would put the
            // useful page last.
            ->latest('recorded_at')
            ->paginate(min((int) $request->integer('per_page', 100), 500));

        return ApiResponse::paginated($paginator, FuelReadingResource::collection($paginator), [
            'current' => [
                'fuel_pct' => $vehicle->current_fuel_pct,
                'fuel_litres' => $vehicle->current_fuel_litres,
                'recorded_at' => $vehicle->fuel_level_at?->toIso8601String(),
                'status' => $this->fuelLevels->statusFor($vehicle->current_fuel_pct),
            ],
            'tank_capacity' => $vehicle->tank_capacity,
        ]);
    }

    /**
     * @OA\Get(path="/vehicles/{vehicle}/efficiency", tags={"Vehicles"}, security={{"bearerAuth":{}}},
     *   summary="Efficiency trend and baseline deviation",
     *
     *   @OA\Response(response=200, description="Efficiency analytics"))
     */
    public function efficiency(Vehicle $vehicle): JsonResponse
    {
        $this->authorize('view', $vehicle);

        $series = $vehicle->fuelPurchases()
            ->whereNotNull('km_per_litre')
            ->latest('purchased_at')
            ->limit(30)
            ->get(['purchased_at', 'km_per_litre', 'cost_per_km', 'price_per_litre'])
            ->reverse()
            ->map(static fn ($p) => [
                'date' => $p->purchased_at->toDateString(),
                'km_per_litre' => $p->km_per_litre,
                'cost_per_km' => $p->cost_per_km,
                'price_per_litre' => $p->price_per_litre,
            ])->values()->all();

        return ApiResponse::success([
            'baseline_km_per_litre' => $vehicle->baseline_km_per_litre,
            'avg_km_per_litre' => $vehicle->avg_km_per_litre,
            'deviation_pct' => $vehicle->efficiencyDeviationPct(),
            'estimated_range_km' => $vehicle->estimatedRangeKm(),
            'series' => $series,
            'from_trips' => $this->tripDistance($vehicle),
        ]);
    }

    /**
     * Distance recorded by completed trips over the last 90 days.
     *
     * Distance only — deliberately not a fuel economy figure.
     *
     * Dividing this by litres bought in the same window looked reasonable and
     * produced 0.33 km/L on the first real vehicle it met: 368 litres against
     * 120 km, because trips are new and almost none of that vehicle's driving
     * had been recorded as one. The arithmetic was right and the number was a
     * lie — it reads as an engine about to seize. An approximation like that
     * only holds when trips capture nearly all the driving, which is false for
     * any fleet still adopting them, and nothing here can tell the difference
     * between a thirsty vehicle and an unrecorded journey.
     *
     * So trips report what they actually know. Fuel economy stays with
     * tank-to-tank km/L, which trips now support properly by keeping the
     * odometer moving between fills — see TripDispatchService::recordOdometer.
     *
     * @return array{distance_km: float, trips: int}|null
     */
    private function tripDistance(Vehicle $vehicle): ?array
    {
        $from = now()->subDays(90);

        $completed = Trip::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->where('status', Trip::STATUS_COMPLETED)
            ->where('ended_at', '>=', $from);

        $distance = (float) (clone $completed)->sum('distance_km');

        // Nothing driven is not "zero economy", it is no answer.
        if ($distance <= 0.0) {
            return null;
        }

        return [
            'distance_km' => round($distance, 2),
            // Only the trips that contributed to it. The count sits beside the
            // distance, so counting trips that recorded none implies an average
            // per trip that never happened — a fleet where half the drivers
            // skip the odometer would read as one doing half the mileage.
            'trips' => (clone $completed)->where('distance_km', '>', 0)->count(),
        ];
    }
}
