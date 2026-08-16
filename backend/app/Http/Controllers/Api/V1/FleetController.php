<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ai\Models\FraudAlert;
use App\Domain\Fleet\Models\DeviceLocation;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Fleet\Models\Fleet;
use App\Domain\Fleet\Services\FleetOverviewService;
use App\Domain\Reporting\Services\DashboardService;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserDevice;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Models\VehicleAssignment;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fleet\StoreDriverRequest;
use App\Http\Requests\Fleet\UpdateDriverRequest;
use App\Http\Resources\DeviceLocationResource;
use App\Http\Resources\DriverResource;
use App\Support\Exceptions\DomainException;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * @OA\Tag(name="Fleet", description="Fleets, drivers, assignments and fraud alerts")
 */
class FleetController extends Controller
{
    public function __construct(
        private readonly DashboardService $dashboards,
        private readonly FleetOverviewService $overview,
    ) {}

    /**
     * @OA\Get(path="/fleet", tags={"Fleet"}, security={{"bearerAuth":{}}},
     *   summary="Fleets in the caller's company", @OA\Response(response=200, description="Fleets"))
     */
    public function index(Request $request): JsonResponse
    {
        // Tenant scoping says which fleet; the permission says whether you may read one at all.
        abort_unless($request->user()->can('fleet.view'), 403);

        $fleets = Fleet::query()
            ->forUser($request->user())
            ->withCount(['vehicles', 'drivers'])
            ->with('manager:id,first_name,last_name', 'baseCity:id,name')
            ->get()
            ->map(static fn (Fleet $fleet) => [
                'id' => $fleet->getKey(),
                'name' => $fleet->name,
                'code' => $fleet->code,
                'manager' => $fleet->manager?->full_name,
                'base_city' => $fleet->baseCity?->name,
                'vehicles_count' => $fleet->vehicles_count,
                'drivers_count' => $fleet->drivers_count,
                'monthly_fuel_budget' => $fleet->monthly_fuel_budget,
                'budget_utilisation_pct' => $fleet->budgetUtilisation(),
            ]);

        return ApiResponse::success($fleets->all());
    }

    /**
     * @OA\Get(path="/fleet/dashboard", tags={"Fleet"}, security={{"bearerAuth":{}}},
     *   summary="Fleet operations dashboard",
     *
     *   @OA\Parameter(name="fleet_id", in="query", @OA\Schema(type="integer")),
     *
     *   @OA\Response(response=200, description="Vehicles, drivers, spend, fraud and maintenance"))
     */
    /**
     * Fleet-wide aggregates for one company.
     *
     * Note for platform administrators: this returns 403 for an account with
     * no `company_id`, which includes the seeded super administrator. That is
     * deliberate and unchanged — the aggregates are computed for a single
     * tenant, and a platform admin has no default one. They can still read
     * /fleet, /fleet/drivers and /fleet/fraud-alerts, which are scoped by the
     * query rather than by the caller's company. Choosing a company to inspect
     * would need either a company parameter or impersonation; neither exists,
     * and neither is implied by this endpoint.
     */
    public function dashboard(Request $request): JsonResponse
    {
        // Fleet-wide aggregates. Scoping alone let any signed-in company member read them.
        abort_unless($request->user()->can('fleet.view'), 403);

        $user = $request->user();

        /*
         * A platform administrator has no company by design — that is what
         * makes tenant scoping work — so there is no fleet to show them here.
         * Carries its own code rather than a bare 403 so the client can say
         * which of the two 403s this is: not entitled, or not in a tenant.
         * Without it the page rendered a grid of dashes and looked broken.
         */
        if ($user->company_id === null) {
            throw new DomainException(
                'Your account is not linked to a company, so there is no fleet to show.',
                'company_required',
                403,
            );
        }

        $fleetId = $request->has('fleet_id') ? (int) $request->integer('fleet_id') : null;

        /*
         * Nested under `overview` rather than spread across the top level.
         * forFleet already publishes `summary`, `vehicles` and `maintenance`
         * with different meanings — an expense total, a statistics object and a
         * pair of counts — and merging would have silently dropped one side of
         * every collision. PHP's array union keeps the left operand, so the
         * fleet page would have kept working while the new dashboard read nulls.
         */
        return ApiResponse::success(
            $this->dashboards->forFleet($user->company_id, $fleetId)
            + ['overview' => $this->overview->forCompany($user->company_id, $fleetId)],
        );
    }

    /**
     * @OA\Delete(path="/fleet/vehicles/{vehicle}/assignment", tags={"Fleet"}, security={{"bearerAuth":{}}},
     *   summary="Release whoever is currently driving this vehicle",
     *
     *   @OA\Response(response=200, description="Released, or already free"),
     *   @OA\Response(response=403, description="Not entitled to manage this vehicle"))
     */
    public function releaseAssignment(Request $request, Vehicle $vehicle): JsonResponse
    {
        // The same two questions as assigning. `update` says the vehicle is
        // yours to touch — which an assigned driver passes, since they record
        // odometer readings — and the management capability says deciding who
        // drives it is your call rather than theirs.
        $this->authorize('update', $vehicle);
        abort_unless(
            $request->user()->canAny(['fleet.assign_drivers', 'fleet.manage']),
            403,
            'Releasing a driver from a vehicle requires fleet management permission.',
        );

        // Released rather than deleted: the row is the record that somebody
        // drove this vehicle between two dates, and fuel and fraud reporting
        // read it. Removing it would rewrite history to tidy a screen.
        $released = VehicleAssignment::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->whereNull('released_at')
            ->update(['released_at' => now()]);

        return ApiResponse::success(
            ['released' => $released],
            $released > 0
                ? 'Driver released from '.$vehicle->plate_number.'.'
                : 'That vehicle already had no driver assigned.',
        );
    }

    /**
     * @OA\Get(path="/fleet/drivers", tags={"Fleet"}, security={{"bearerAuth":{}}},
     *   summary="Drivers in the caller's company", @OA\Response(response=200, description="Drivers"))
     */
    public function drivers(Request $request): JsonResponse
    {
        // A roster of colleagues is not something every company member should read.
        abort_unless($request->user()->can('drivers.view'), 403);

        $paginator = Driver::query()
            ->forUser($request->user())
            ->when($request->has('fleet_id'), fn ($q) => $q->where('fleet_id', $request->integer('fleet_id')))
            ->when($request->has('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->with('fleet:id,name', 'currentAssignment.vehicle:id,plate_number')
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return ApiResponse::paginated($paginator, DriverResource::collection($paginator));
    }

    /**
     * @OA\Post(path="/fleet/drivers", tags={"Fleet"}, security={{"bearerAuth":{}}},
     *   summary="Add a driver to the fleet",
     *
     *   @OA\Response(response=201, description="Created"),
     *   @OA\Response(response=403, description="Not entitled, or the record named is another tenant's"))
     */
    public function storeDriver(StoreDriverRequest $request): JsonResponse
    {
        $this->authorize('create', Driver::class);

        $actor = $request->user();
        $data = $request->validated();

        $companyId = $this->companyForDriver($actor, $data['company_id'] ?? null);

        // Every foreign key is re-checked against that company. `exists` proved
        // the row is there; it never proved it was the caller's to point at.
        $this->assertBelongsToCompany('users', $data['user_id'] ?? null, $companyId, 'user');
        $this->assertBelongsToCompany('fleets', $data['fleet_id'] ?? null, $companyId, 'fleet');

        $driver = Driver::create($data + ['company_id' => $companyId]);

        return ApiResponse::created(new DriverResource($driver->load('fleet:id,name')));
    }

    /**
     * @OA\Patch(path="/fleet/drivers/{driver}", tags={"Fleet"}, security={{"bearerAuth":{}}},
     *   summary="Update a driver",
     *
     *   @OA\Response(response=200, description="Updated"),
     *   @OA\Response(response=403, description="Not entitled to this driver"))
     */
    public function updateDriver(UpdateDriverRequest $request, Driver $driver): JsonResponse
    {
        // Route model binding resolves by id without the tenancy scope, so the
        // policy is what stops an id from being an entitlement.
        $this->authorize('update', $driver);

        $data = $request->validated();

        $this->assertBelongsToCompany('users', $data['user_id'] ?? null, $driver->company_id, 'user');
        $this->assertBelongsToCompany('fleets', $data['fleet_id'] ?? null, $driver->company_id, 'fleet');

        $driver->update($data);

        return ApiResponse::success(new DriverResource($driver->refresh()->load('fleet:id,name')));
    }

    /**
     * The company a new driver belongs to.
     *
     * A platform administrator may name any tenant. Anyone else creates inside
     * their own and nowhere else, and naming another is refused rather than
     * quietly redirected — a silent redirect hides an attempt worth seeing.
     */
    private function companyForDriver(User $actor, ?int $requested): ?int
    {
        if ($actor->isPlatformAdministrator()) {
            /*
             * Named, never inferred. A platform administrator has no company —
             * that is what makes them one — so the old fallback to their own
             * `company_id` could only ever yield null, and wrote a driver no
             * tenant could see: absent from every roster and every assignment
             * picker, while still counting as a driver row. It failed silently
             * with a 201, and produced exactly one such record in production.
             */
            if ($requested === null) {
                throw new DomainException(
                    'Name the company this driver belongs to. A platform administrator has no company to infer one from.',
                    'company_required',
                    422,
                );
            }

            return $requested;
        }

        if ($requested !== null && $requested !== $actor->company_id) {
            abort(403, 'You may only add drivers to your own company.');
        }

        return $actor->company_id;
    }

    /**
     * Refuse a foreign key that points outside the driver's own company.
     *
     * Without this, `exists:users,id` would happily accept another tenant's
     * user and quietly attach them to this fleet.
     */
    private function assertBelongsToCompany(string $table, ?int $id, ?int $companyId, string $label): void
    {
        if ($id === null) {
            return;
        }

        $owner = DB::table($table)->where('id', $id)->value('company_id');

        abort_unless($owner !== null && $owner === $companyId, 403, "That {$label} belongs to another company.");
    }

    /**
     * @OA\Post(path="/fleet/assignments", tags={"Fleet"}, security={{"bearerAuth":{}}},
     *   summary="Assign a driver to a vehicle",
     *
     *   @OA\Response(response=201, description="Assigned; any previous assignment is released"))
     */
    public function assign(Request $request): JsonResponse
    {
        $data = $request->validate([
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'driver_id' => ['required', 'integer', 'exists:drivers,id'],
            'notes' => ['nullable', 'string', 'max:255'],
        ]);

        $vehicle = Vehicle::findOrFail($data['vehicle_id']);
        $driver = Driver::findOrFail($data['driver_id']);

        // Two separate questions, and conflating them was the bug. `update` on
        // the vehicle answers "is this vehicle yours to touch", which an
        // assigned driver legitimately passes -- they record odometer
        // readings. Deciding who drives what is a management action, so it is
        // gated on the management capability as well.
        $this->authorize('update', $vehicle);
        abort_unless(
            $request->user()->canAny(['fleet.assign_drivers', 'fleet.manage']),
            403,
            'Assigning drivers to vehicles requires fleet management permission.',
        );
        abort_unless($driver->company_id === $vehicle->company_id, 422, 'Driver and vehicle belong to different companies.');

        // A vehicle and a driver may each hold only one live assignment.
        VehicleAssignment::where('vehicle_id', $vehicle->getKey())->whereNull('released_at')->update(['released_at' => now()]);
        VehicleAssignment::where('driver_id', $driver->getKey())->whereNull('released_at')->update(['released_at' => now()]);

        $assignment = VehicleAssignment::create([
            'vehicle_id' => $vehicle->getKey(),
            'driver_id' => $driver->getKey(),
            'assigned_at' => now(),
            'assigned_by' => $request->user()->getKey(),
            'notes' => $data['notes'] ?? null,
        ]);

        return ApiResponse::created([
            'id' => $assignment->getKey(),
            'vehicle' => $vehicle->plate_number,
            'driver' => $driver->full_name,
            'assigned_at' => $assignment->assigned_at->toIso8601String(),
        ]);
    }

    /**
     * @OA\Get(path="/fleet/locations", tags={"Fleet"}, security={{"bearerAuth":{}}},
     *   summary="Latest known position of each tracked vehicle",
     *
     *   @OA\Response(response=200, description="One row per vehicle with a reporting device"))
     */
    public function vehicleLocations(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('devices.location.view'), 403);

        // Read from the device's cached position rather than aggregating the
        // history table: one row per vehicle, no window function, and the cache
        // is maintained on write precisely so this query stays trivial.
        $devices = UserDevice::query()
            ->active()
            ->whereNotNull('vehicle_id')
            ->whereNotNull('last_location_at')
            ->whereHas('vehicle', fn ($q) => $q->forUser($request->user()))
            ->with([
                'vehicle:id,plate_number,nickname,make_id,model_id,status',
                'user:id,first_name,last_name',
            ])
            ->get();

        return ApiResponse::success($devices->map(static fn (UserDevice $device) => [
            'vehicle_id' => $device->vehicle_id,
            'plate_number' => $device->vehicle?->plate_number,
            'display_name' => $device->vehicle?->display_name,
            'device_id' => $device->getKey(),
            'driver_name' => $device->user?->full_name,
            'latitude' => $device->last_latitude,
            'longitude' => $device->last_longitude,
            'recorded_at' => $device->last_location_at?->toIso8601String(),
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            // Freshness is decided here, not on the map. The threshold is one
            // setting shared with device health, and a client that re-derived
            // it would drift from the server the day the setting changes.
            'is_fresh' => $device->hasFreshLocation(),
        ])->values()->all());
    }

    /**
     * @OA\Get(path="/fleet/vehicles/{vehicle}/locations", tags={"Fleet"}, security={{"bearerAuth":{}}},
     *   summary="Location history for one vehicle",
     *
     *   @OA\Parameter(name="from", in="query", @OA\Schema(type="string", format="date-time")),
     *   @OA\Parameter(name="to", in="query", @OA\Schema(type="string", format="date-time")),
     *
     *   @OA\Response(response=200, description="Bounded, ordered history"),
     *   @OA\Response(response=422, description="Window too wide"))
     */
    public function vehicleLocationHistory(Request $request, Vehicle $vehicle): JsonResponse
    {
        // Two gates, not one. Seeing where a vehicle is now and reconstructing
        // where it has been are different questions about a person's movements,
        // so history carries its own permission on top of the vehicle policy.
        $this->authorize('view', $vehicle);
        abort_unless($request->user()->can('devices.location.history'), 403);

        $data = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $maxDays = (int) config('fip.location.max_history_days');

        $to = isset($data['to']) ? Carbon::parse($data['to']) : now();
        $from = isset($data['from']) ? Carbon::parse($data['from']) : $to->copy()->subDay();

        if ($from->diffInDays($to) > $maxDays) {
            throw new DomainException(
                sprintf('A history window may span at most %d days.', $maxDays),
                'history_window_too_wide',
                422,
            );
        }

        $paginator = DeviceLocation::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->whereBetween('recorded_at', [$from, $to])
            // Oldest first: a track is read forwards, and a caller drawing a
            // line does not want to reverse the page.
            ->orderBy('recorded_at')
            ->paginate(min(
                (int) $request->integer('per_page', 500),
                (int) config('fip.location.max_history_rows'),
            ));

        return ApiResponse::paginated($paginator, DeviceLocationResource::collection($paginator), [
            'window' => ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String()],
        ]);
    }

    /**
     * @OA\Get(path="/fleet/fraud-alerts", tags={"Fleet"}, security={{"bearerAuth":{}}},
     *   summary="Fuel fraud alerts for the caller's company",
     *
     *   @OA\Response(response=200, description="Alerts with evidence"))
     */
    public function fraudAlerts(Request $request): JsonResponse
    {
        // Company-wide alerts. Drivers read their own vehicle via /vehicles/{vehicle}/alerts instead.
        abort_unless($request->user()->can('fraud.view'), 403);

        $paginator = FraudAlert::query()
            ->forUser($request->user())
            ->when($request->has('status'), fn ($q) => $q->where('status', $request->string('status')->toString()), fn ($q) => $q->unresolved())
            ->when($request->has('severity'), fn ($q) => $q->where('severity', $request->string('severity')->toString()))
            // `reading` joins the level-based alerts: an alert raised by a fuel
            // drop has no purchase behind it, so without this the operator sees
            // a severity and a score with nothing to look at.
            ->with(['vehicle:id,plate_number,nickname', 'driver:id,first_name,last_name', 'purchase', 'reading'])
            ->latest('detected_at')
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return ApiResponse::paginated($paginator);
    }

    /**
     * @OA\Patch(path="/fleet/fraud-alerts/{alert}", tags={"Fleet"}, security={{"bearerAuth":{}}},
     *   summary="Resolve or dismiss a fraud alert", @OA\Response(response=200, description="Updated"))
     */
    public function resolveFraudAlert(Request $request, FraudAlert $alert): JsonResponse
    {
        // Company membership says the alert is yours to see. Resolving it is a
        // different capability, and `fraud.resolve` already exists to express
        // it -- the check was simply never made, so anyone in the company
        // could close an alert, including the driver it was raised against.
        abort_unless(
            $request->user()->isPlatformAdministrator() || $alert->company_id === $request->user()->company_id,
            403,
        );
        abort_unless(
            $request->user()->can('fraud.resolve'),
            403,
            'Resolving a fuel alert requires the fraud.resolve permission.',
        );

        $data = $request->validate([
            'status' => ['required', 'string', 'in:investigating,confirmed,dismissed'],
            'resolution_note' => ['nullable', 'string', 'max:500'],
        ]);

        $alert->update($data + [
            'resolved_by' => $request->user()->getKey(),
            'resolved_at' => in_array($data['status'], ['confirmed', 'dismissed'], true) ? now() : null,
        ]);

        return ApiResponse::success($alert->fresh()->toArray(), 'Alert updated.');
    }
}
