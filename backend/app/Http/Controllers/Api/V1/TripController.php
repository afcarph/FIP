<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Expense\Models\Trip;
use App\Domain\Fleet\Services\TripDispatchService;
use App\Http\Controllers\Controller;
use App\Http\Resources\TripResource;
use App\Policies\TripPolicy;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="Trips", description="Trip planning and dispatch")
 */
class TripController extends Controller
{
    private const WITH = ['vehicle:id,plate_number', 'driver:id,first_name,last_name'];

    public function __construct(private readonly TripDispatchService $trips) {}

    /**
     * @OA\Get(path="/fleet/trips", tags={"Trips"}, security={{"bearerAuth":{}}},
     *   summary="Trips in the caller's company", @OA\Response(response=200, description="Trips"))
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Trip::class);

        $actor = $request->user();

        $paginator = Trip::query()
            // The tenancy filter is the scope, not the status filter below it.
            ->forUser($actor)
            // A driver holds no planning grant, so the tenant scope alone would
            // hand them every trip their company runs. Narrowed to their own.
            ->when(
                app(TripPolicy::class)->isOperatorOnly($actor),
                fn ($q) => $q->where('driver_id', $actor->driverProfile?->getKey() ?? 0),
            )
            ->when($request->filled('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->when($request->filled('vehicle_id'), fn ($q) => $q->where('vehicle_id', $request->integer('vehicle_id')))
            ->with(self::WITH)
            ->latest('id')
            ->paginate(min((int) $request->integer('per_page', 25), 100));

        return ApiResponse::paginated($paginator, TripResource::collection($paginator));
    }

    /**
     * @OA\Get(path="/fleet/trips/summary", tags={"Trips"}, security={{"bearerAuth":{}}},
     *   summary="Counts by status", @OA\Response(response=200, description="Summary"))
     */
    public function summary(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Trip::class);

        $actor = $request->user();

        $counts = Trip::query()
            ->forUser($actor)
            // Narrowed the same way the listing is: counting a company's whole
            // workload for a driver would leak through the tiles what the list
            // itself refuses to show.
            ->when(
                app(TripPolicy::class)->isOperatorOnly($actor),
                fn ($q) => $q->where('driver_id', $actor->driverProfile?->getKey() ?? 0),
            )
            ->selectRaw('status, COUNT(*) AS total')
            ->groupBy('status')
            ->pluck('total', 'status');

        // Every status present, so a client can render five tiles without
        // deciding what a missing key means.
        $summary = collect(array_keys(Trip::TRANSITIONS))
            ->mapWithKeys(fn (string $s) => [$s => (int) ($counts[$s] ?? 0)])
            ->all();

        return ApiResponse::success($summary + ['total' => array_sum($summary)]);
    }

    /**
     * @OA\Post(path="/fleet/trips", tags={"Trips"}, security={{"bearerAuth":{}}},
     *   summary="Plan a trip", @OA\Response(response=201, description="Created in draft"))
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Trip::class);

        $data = $request->validate([
            // Entitlement to these ids is proven in the service against the
            // caller's own fleet; `exists` only proves a row is there.
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'driver_id' => ['required', 'integer', 'exists:drivers,id'],
            'origin_label' => ['required', 'string', 'max:180'],
            'destination_label' => ['required', 'string', 'max:180'],
            'purpose' => ['nullable', 'string', 'max:180'],
            'scheduled_for' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $trip = $this->trips->create($request->user(), $data);

        return ApiResponse::created(new TripResource($trip->load(self::WITH)), 'Trip '.$trip->reference_no.' created.');
    }

    /**
     * @OA\Get(path="/fleet/trips/{trip}", tags={"Trips"}, security={{"bearerAuth":{}}},
     *   summary="One trip", @OA\Response(response=200, description="Trip"))
     */
    public function show(Trip $trip): JsonResponse
    {
        $this->authorize('view', $trip);

        return ApiResponse::success(new TripResource($trip->load([...self::WITH, 'creator:id,first_name,last_name'])));
    }

    /**
     * @OA\Patch(path="/fleet/trips/{trip}", tags={"Trips"}, security={{"bearerAuth":{}}},
     *   summary="Amend a trip that has not been sent out",
     *
     *   @OA\Response(response=200, description="Updated"),
     *   @OA\Response(response=403, description="Already dispatched, or not yours"))
     */
    public function update(Request $request, Trip $trip): JsonResponse
    {
        // The policy allows this only for a draft: once dispatched, a driver
        // has been told where they are going.
        $this->authorize('update', $trip);

        $data = $request->validate([
            'vehicle_id' => ['sometimes', 'integer', 'exists:vehicles,id'],
            'driver_id' => ['sometimes', 'integer', 'exists:drivers,id'],
            'origin_label' => ['sometimes', 'string', 'max:180'],
            'destination_label' => ['sometimes', 'string', 'max:180'],
            'purpose' => ['sometimes', 'nullable', 'string', 'max:180'],
            'scheduled_for' => ['sometimes', 'nullable', 'date'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ]);

        return ApiResponse::success(
            new TripResource($this->trips->update($request->user(), $trip, $data)->load(self::WITH)),
            'Trip '.$trip->reference_no.' updated.',
        );
    }

    /**
     * @OA\Post(path="/fleet/trips/{trip}/dispatch", tags={"Trips"}, security={{"bearerAuth":{}}},
     *   summary="Send the trip out", @OA\Response(response=200, description="Dispatched"))
     */
    public function dispatchTrip(Trip $trip): JsonResponse
    {
        $this->authorize('dispatch', $trip);

        return ApiResponse::success(
            new TripResource($this->trips->dispatch($trip)->load(self::WITH)),
            'Trip '.$trip->reference_no.' dispatched.',
        );
    }

    /**
     * @OA\Post(path="/fleet/trips/{trip}/start", tags={"Trips"}, security={{"bearerAuth":{}}},
     *   summary="Record that the vehicle has left", @OA\Response(response=200, description="In progress"))
     */
    public function start(Request $request, Trip $trip): JsonResponse
    {
        $this->authorize('operate', $trip);

        $data = $request->validate(['odometer_start' => ['nullable', 'integer', 'min:0']]);

        return ApiResponse::success(
            new TripResource($this->trips->start($trip, $data['odometer_start'] ?? null)->load(self::WITH)),
            'Trip '.$trip->reference_no.' started.',
        );
    }

    /**
     * @OA\Post(path="/fleet/trips/{trip}/complete", tags={"Trips"}, security={{"bearerAuth":{}}},
     *   summary="Close a finished trip",
     *
     *   @OA\Response(response=200, description="Completed"),
     *   @OA\Response(response=422, description="Closing odometer below the opening reading"))
     */
    public function complete(Request $request, Trip $trip): JsonResponse
    {
        $this->authorize('operate', $trip);

        $data = $request->validate([
            'odometer_end' => ['nullable', 'integer', 'min:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        return ApiResponse::success(
            new TripResource(
                $this->trips->complete($trip, $data['odometer_end'] ?? null, $data['notes'] ?? null)->load(self::WITH),
            ),
            'Trip '.$trip->reference_no.' completed.',
        );
    }

    /**
     * @OA\Post(path="/fleet/trips/{trip}/cancel", tags={"Trips"}, security={{"bearerAuth":{}}},
     *   summary="Call off a trip that has not started",
     *
     *   @OA\Response(response=200, description="Cancelled"),
     *   @OA\Response(response=422, description="Already started, or already finished"))
     */
    public function cancel(Request $request, Trip $trip): JsonResponse
    {
        $this->authorize('cancel', $trip);

        // Required, because a cancelled trip with no reason tells whoever reads
        // the history later nothing they can act on.
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        return ApiResponse::success(
            new TripResource($this->trips->cancel($trip, $data['reason'])->load(self::WITH)),
            'Trip '.$trip->reference_no.' cancelled.',
        );
    }
}
