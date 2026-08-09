<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Fleet\Services\DeviceRegistrationService;
use App\Domain\Fleet\Services\LocationIngestService;
use App\Domain\User\Models\UserDevice;
use App\Domain\Vehicle\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Device\RegisterDeviceRequest;
use App\Http\Requests\Device\StoreLocationBatchRequest;
use App\Http\Resources\DeviceResource;
use App\Support\Exceptions\DomainException;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="Devices", description="Device registration and location reporting")
 */
class DeviceController extends Controller
{
    public function __construct(
        private readonly DeviceRegistrationService $devices,
        private readonly LocationIngestService $locations,
    ) {}

    /**
     * @OA\Get(path="/devices", tags={"Devices"}, security={{"bearerAuth":{}}},
     *   summary="Devices registered to the caller", @OA\Response(response=200, description="Devices"))
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', UserDevice::class);

        $devices = UserDevice::query()
            ->forUser($request->user())
            ->with('vehicle:id,plate_number,nickname,make_id,model_id')
            ->latest('last_seen_at')
            ->get();

        return ApiResponse::success(DeviceResource::collection($devices)->resolve());
    }

    /**
     * @OA\Post(path="/devices", tags={"Devices"}, security={{"bearerAuth":{}}},
     *   summary="Register this device, or refresh its registration",
     *
     *   @OA\Response(response=201, description="Registered"),
     *   @OA\Response(response=403, description="The device has been revoked"))
     */
    public function store(RegisterDeviceRequest $request): JsonResponse
    {
        $device = $this->devices->register($request->user(), $request->validated());

        return ApiResponse::created(
            new DeviceResource($device->load('vehicle')),
            'Device registered.',
        );
    }

    /**
     * @OA\Get(path="/devices/{device}", tags={"Devices"}, security={{"bearerAuth":{}}},
     *   summary="Device detail", @OA\Response(response=200, description="Device"))
     */
    public function show(UserDevice $device): JsonResponse
    {
        $this->authorize('view', $device);

        return ApiResponse::success(new DeviceResource($device->load('vehicle')));
    }

    /**
     * @OA\Patch(path="/devices/{device}", tags={"Devices"}, security={{"bearerAuth":{}}},
     *   summary="Rename a device, or point it at a vehicle",
     *
     *   @OA\Response(response=200, description="Updated"),
     *   @OA\Response(response=403, description="Not entitled to the device or the vehicle"))
     */
    public function update(Request $request, UserDevice $device): JsonResponse
    {
        $this->authorize('update', $device);

        $data = $request->validate([
            'device_name' => ['nullable', 'string', 'max:120'],
            // Null detaches. Absent leaves the association alone — the two are
            // different intentions and a PATCH has to tell them apart.
            'vehicle_id' => ['sometimes', 'nullable', 'integer', 'exists:vehicles,id'],
        ]);

        if (array_key_exists('device_name', $data)) {
            $device->forceFill(['device_name' => $data['device_name']])->save();
        }

        if (array_key_exists('vehicle_id', $data)) {
            if ($data['vehicle_id'] === null) {
                $this->devices->detachFromVehicle($device);
            } else {
                $vehicle = Vehicle::findOrFail($data['vehicle_id']);

                // The second gate, and the one that matters: holding your own
                // phone does not entitle you to attach it to someone's truck.
                $this->authorize('update', $vehicle);

                $this->devices->assignToVehicle($device, $vehicle);
            }
        }

        return ApiResponse::success(new DeviceResource($device->refresh()->load('vehicle')));
    }

    /**
     * @OA\Delete(path="/devices/{device}", tags={"Devices"}, security={{"bearerAuth":{}}},
     *   summary="Revoke a device",
     *
     *   @OA\Response(response=200, description="Revoked; history is retained"))
     */
    public function destroy(Request $request, UserDevice $device): JsonResponse
    {
        $this->authorize('revoke', $device);

        $reason = $request->validate([
            'reason' => ['nullable', 'string', 'max:255'],
        ])['reason'] ?? null;

        $this->devices->revoke($device, $request->user(), $reason);

        return ApiResponse::success(
            new DeviceResource($device->refresh()),
            'Device revoked. Its location history is retained.',
        );
    }

    /**
     * @OA\Post(path="/devices/location", tags={"Devices"}, security={{"bearerAuth":{}}},
     *   summary="Report a batch of positions from the calling device",
     *
     *   @OA\Response(response=200, description="Counts of accepted, duplicate and rejected points"),
     *   @OA\Response(response=403, description="Device unknown, unassigned or revoked"),
     *   @OA\Response(response=422, description="Batch too large"))
     */
    public function storeLocation(StoreLocationBatchRequest $request): JsonResponse
    {
        // The device is resolved from the session plus the header, never from
        // the body. Copying another device's identifier into a request gets
        // nowhere without also being that device's owner.
        $device = $this->devices->resolve($request->user(), $request->header('X-Device-Id'));

        if ($device === null) {
            throw new DomainException(
                'This device is not registered. Register it before reporting location.',
                'device_not_registered',
                403,
            );
        }

        $result = $this->locations->ingest($device, $request->validated()['points']);

        return ApiResponse::success($result, sprintf(
            '%d position(s) recorded.',
            $result['accepted'],
        ));
    }
}
