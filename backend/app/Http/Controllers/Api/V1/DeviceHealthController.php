<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Fleet\Services\DeviceHealthService;
use App\Domain\User\Models\UserDevice;
use App\Http\Controllers\Controller;
use App\Http\Resources\DeviceHealthResource;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Device health for the people who run the vehicles.
 *
 * Separate from DeviceController because the two have opposite subjects: that
 * one is a device acting on itself, this one is an operator reading across a
 * fleet. Keeping them apart keeps the authorization obvious — every route here
 * is a tenancy-scoped read, and none of them writes anything.
 *
 * @OA\Tag(name="Device health", description="Operational status of fleet tracking devices")
 */
class DeviceHealthController extends Controller
{
    public function __construct(private readonly DeviceHealthService $health) {}

    /**
     * @OA\Get(path="/fleet/devices", tags={"Device health"}, security={{"bearerAuth":{}}},
     *   summary="Health of the devices reporting for this fleet",
     *
     *   @OA\Response(response=200, description="Devices"),
     *   @OA\Response(response=403, description="Not entitled to fleet device health"))
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewFleetHealth', UserDevice::class);

        // Validated rather than passed through: the filter reaches a match()
        // that falls through to "everything", so an unrecognised value would
        // quietly widen the result instead of failing.
        $data = $request->validate([
            'filter' => ['sometimes', 'nullable', Rule::in(DeviceHealthService::FILTERS)],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
        ]);

        $paginator = $this->health
            ->fleetQuery($request->user(), $data['filter'] ?? null)
            ->orderByRaw('last_seen_at IS NULL DESC')   // never-seen devices first: they need attention most
            ->orderBy('last_seen_at')
            ->paginate((int) ($data['per_page'] ?? 25));

        return ApiResponse::paginated(
            $paginator,
            DeviceHealthResource::collection($paginator),
            ['summary' => $this->health->summary($request->user())],
        );
    }

    /**
     * @OA\Get(path="/fleet/devices/{device}", tags={"Device health"}, security={{"bearerAuth":{}}},
     *   summary="Health of one device",
     *
     *   @OA\Response(response=200, description="Device"),
     *   @OA\Response(response=403, description="Not entitled to this device"))
     */
    public function show(UserDevice $device): JsonResponse
    {
        // The vehicle is loaded before the check, not after: the policy decides
        // on the vehicle's company, and an unloaded relation would make it
        // decide on null and refuse everybody.
        $device->load(['user:id,first_name,last_name,email', 'vehicle:id,plate_number,nickname,company_id']);

        $this->authorize('viewHealth', $device);

        return ApiResponse::success(new DeviceHealthResource($device));
    }
}
