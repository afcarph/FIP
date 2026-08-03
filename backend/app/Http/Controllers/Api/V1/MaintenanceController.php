<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Maintenance\Models\MaintenanceSchedule;
use App\Domain\Maintenance\Models\MaintenanceType;
use App\Domain\Maintenance\Services\MaintenanceService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Maintenance\StoreMaintenanceRecordRequest;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="Maintenance", description="Service schedules, records and predictions")
 */
class MaintenanceController extends Controller
{
    public function __construct(private readonly MaintenanceService $maintenance) {}

    /**
     * @OA\Get(path="/maintenance/types", tags={"Maintenance"},
     *   summary="Reference list of service items", @OA\Response(response=200, description="Types"))
     */
    public function types(): JsonResponse
    {
        return ApiResponse::success(MaintenanceType::orderBy('category')->orderBy('name')->get()->all());
    }

    /**
     * @OA\Get(path="/vehicles/{vehicle}/maintenance", tags={"Maintenance"}, security={{"bearerAuth":{}}},
     *   summary="Schedules and history for a vehicle",
     *
     *   @OA\Response(response=200, description="Schedule, upcoming items and history"))
     */
    public function index(Request $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorize('view', $vehicle);

        return ApiResponse::success([
            'upcoming' => $this->maintenance->upcomingFor($vehicle, (int) $request->integer('days', 90)),
            'schedules' => $vehicle->maintenanceSchedules()->with('type')->get()->map(static fn (MaintenanceSchedule $s) => [
                'id' => $s->getKey(),
                'service' => $s->type?->name,
                'category' => $s->type?->category,
                'status' => $s->status,
                'interval_km' => $s->interval_km,
                'interval_days' => $s->interval_days,
                'last_performed_at' => $s->last_performed_at?->toDateString(),
                'due_at' => $s->due_at?->toDateString(),
                'due_odometer' => $s->due_odometer,
                'km_remaining' => $s->kilometresRemaining($vehicle->current_odometer),
                'predicted_due_at' => $s->predicted_due_at?->toDateString(),
                'prediction_confidence' => $s->prediction_confidence,
            ])->all(),
            'history' => $vehicle->maintenanceRecords()->with('type')->latest('performed_at')->limit(50)->get()->map(static fn ($r) => [
                'id' => $r->getKey(),
                'service' => $r->type?->name,
                'performed_at' => $r->performed_at->toDateString(),
                'odometer' => $r->odometer,
                'cost' => $r->cost,
                'vendor' => $r->vendor,
                'notes' => $r->notes,
            ])->all(),
        ]);
    }

    /**
     * @OA\Post(path="/vehicles/{vehicle}/maintenance", tags={"Maintenance"}, security={{"bearerAuth":{}}},
     *   summary="Record completed service", @OA\Response(response=201, description="Recorded and schedule rolled forward"))
     */
    public function store(StoreMaintenanceRecordRequest $request, Vehicle $vehicle): JsonResponse
    {
        $this->authorize('update', $vehicle);

        $record = $this->maintenance->recordService($vehicle, $request->user(), $request->validated());

        return ApiResponse::created([
            'id' => $record->getKey(),
            'service' => $record->type?->name,
            'performed_at' => $record->performed_at->toDateString(),
            'next_due' => $this->maintenance->upcomingFor($vehicle, 365),
        ]);
    }

    /**
     * @OA\Post(path="/vehicles/{vehicle}/maintenance/predict", tags={"Maintenance"}, security={{"bearerAuth":{}}},
     *   summary="Refresh AI predictions for a vehicle's service items",
     *
     *   @OA\Response(response=200, description="Predictions with confidence"))
     */
    public function predict(Vehicle $vehicle): JsonResponse
    {
        $this->authorize('view', $vehicle);

        return ApiResponse::success([
            'predictions' => $this->maintenance->predictUpcoming($vehicle),
        ]);
    }

    /**
     * @OA\Get(path="/maintenance/due", tags={"Maintenance"}, security={{"bearerAuth":{}}},
     *   summary="Everything due across the caller's vehicles",
     *
     *   @OA\Response(response=200, description="Due and overdue items"))
     */
    public function due(Request $request): JsonResponse
    {
        $vehicleIds = Vehicle::query()->forUser($request->user())->pluck('id');

        $schedules = MaintenanceSchedule::query()
            ->whereIn('vehicle_id', $vehicleIds)
            ->dueWithin((int) $request->integer('days', 30))
            ->with('type', 'vehicle:id,plate_number,nickname')
            ->orderBy('due_at')
            ->get()
            ->map(static fn (MaintenanceSchedule $s) => [
                'id' => $s->getKey(),
                'vehicle_id' => $s->vehicle_id,
                'vehicle' => $s->vehicle?->nickname ?? $s->vehicle?->plate_number,
                'service' => $s->type?->name,
                'icon' => $s->type?->icon,
                'status' => $s->status,
                'due_at' => $s->due_at?->toDateString(),
                'km_remaining' => $s->kilometresRemaining(),
            ]);

        return ApiResponse::success($schedules->all());
    }
}
