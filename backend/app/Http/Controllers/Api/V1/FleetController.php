<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Ai\Models\FraudAlert;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Fleet\Models\Fleet;
use App\Domain\Reporting\Services\DashboardService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Models\VehicleAssignment;
use App\Http\Controllers\Controller;
use App\Http\Resources\DriverResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="Fleet", description="Fleets, drivers, assignments and fraud alerts")
 */
class FleetController extends Controller
{
    public function __construct(private readonly DashboardService $dashboards) {}

    /**
     * @OA\Get(path="/fleet", tags={"Fleet"}, security={{"bearerAuth":{}}},
     *   summary="Fleets in the caller's company", @OA\Response(response=200, description="Fleets"))
     */
    public function index(Request $request): JsonResponse
    {
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
    public function dashboard(Request $request): JsonResponse
    {
        $user = $request->user();

        abort_if($user->company_id === null, 403, 'Your account is not linked to a company.');

        return ApiResponse::success($this->dashboards->forFleet(
            $user->company_id,
            $request->has('fleet_id') ? (int) $request->integer('fleet_id') : null,
        ));
    }

    /**
     * @OA\Get(path="/fleet/drivers", tags={"Fleet"}, security={{"bearerAuth":{}}},
     *   summary="Drivers in the caller's company", @OA\Response(response=200, description="Drivers"))
     */
    public function drivers(Request $request): JsonResponse
    {
        $paginator = Driver::query()
            ->forUser($request->user())
            ->when($request->has('fleet_id'), fn ($q) => $q->where('fleet_id', $request->integer('fleet_id')))
            ->when($request->has('status'), fn ($q) => $q->where('status', $request->string('status')->toString()))
            ->with('fleet:id,name', 'currentAssignment.vehicle:id,plate_number')
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return ApiResponse::paginated($paginator, DriverResource::collection($paginator));
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

        $this->authorize('update', $vehicle);
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
     * @OA\Get(path="/fleet/fraud-alerts", tags={"Fleet"}, security={{"bearerAuth":{}}},
     *   summary="Fuel fraud alerts for the caller's company",
     *
     *   @OA\Response(response=200, description="Alerts with evidence"))
     */
    public function fraudAlerts(Request $request): JsonResponse
    {
        $paginator = FraudAlert::query()
            ->forUser($request->user())
            ->when($request->has('status'), fn ($q) => $q->where('status', $request->string('status')->toString()), fn ($q) => $q->unresolved())
            ->when($request->has('severity'), fn ($q) => $q->where('severity', $request->string('severity')->toString()))
            ->with(['vehicle:id,plate_number,nickname', 'driver:id,first_name,last_name', 'purchase'])
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
        abort_unless(
            $request->user()->isPlatformAdministrator() || $alert->company_id === $request->user()->company_id,
            403,
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
