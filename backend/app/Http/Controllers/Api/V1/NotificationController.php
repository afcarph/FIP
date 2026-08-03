<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Models\PriceAlert;
use App\Domain\Notification\Services\NotificationService;
use App\Http\Controllers\Controller;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @OA\Tag(name="Notifications", description="Notification centre and price alert rules")
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationService $notifications) {}

    /**
     * @OA\Get(path="/notifications", tags={"Notifications"}, security={{"bearerAuth":{}}},
     *   summary="Notification centre",
     *
     *   @OA\Parameter(name="unread", in="query", @OA\Schema(type="boolean")),
     *
     *   @OA\Response(response=200, description="Paginated notifications"))
     */
    public function index(Request $request): JsonResponse
    {
        $paginator = $request->user()->appNotifications()
            ->when($request->boolean('unread'), fn ($q) => $q->unread())
            ->when($request->has('category'), fn ($q) => $q->category($request->string('category')->toString()))
            ->latest()
            ->paginate(min((int) $request->integer('per_page', 20), 100));

        return ApiResponse::paginated($paginator, null, [
            'unread_count' => $this->notifications->unreadCount($request->user()),
        ]);
    }

    /**
     * @OA\Patch(path="/notifications/{notification}/read", tags={"Notifications"}, security={{"bearerAuth":{}}},
     *   summary="Mark one as read", @OA\Response(response=204, description="Marked"))
     */
    public function markRead(Request $request, Notification $notification): JsonResponse
    {
        abort_unless($notification->user_id === $request->user()->getKey(), 403);

        $notification->markRead();

        return ApiResponse::noContent();
    }

    /**
     * @OA\Post(path="/notifications/read-all", tags={"Notifications"}, security={{"bearerAuth":{}}},
     *   summary="Mark everything read", @OA\Response(response=200, description="Count marked"))
     */
    public function markAllRead(Request $request): JsonResponse
    {
        return ApiResponse::success(['marked' => $this->notifications->markAllRead($request->user())]);
    }

    /**
     * @OA\Get(path="/price-alerts", tags={"Notifications"}, security={{"bearerAuth":{}}},
     *   summary="Standing price alert rules", @OA\Response(response=200, description="Alerts"))
     */
    public function alerts(Request $request): JsonResponse
    {
        return ApiResponse::success(
            $request->user()->priceAlerts()->with('fuelType', 'station:id,name', 'city:id,name')->get()->all(),
        );
    }

    /**
     * @OA\Post(path="/price-alerts", tags={"Notifications"}, security={{"bearerAuth":{}}},
     *   summary="Create a price alert rule", @OA\Response(response=201, description="Created"))
     */
    public function storeAlert(Request $request): JsonResponse
    {
        $data = $request->validate([
            'fuel_type_id' => ['required', 'integer', 'exists:fuel_types,id'],
            'station_id' => ['nullable', 'integer', 'exists:gas_stations,id'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'condition' => ['required', 'string', 'in:below,above,any_change'],
            'threshold' => ['required', 'numeric', 'min:0.01', 'max:999.99'],
            'radius_km' => ['nullable', 'numeric', 'min:0.5', 'max:50'],
        ]);

        $alert = $request->user()->priceAlerts()->create($data + ['is_active' => true]);

        return ApiResponse::created($alert->toArray());
    }

    /**
     * @OA\Delete(path="/price-alerts/{alert}", tags={"Notifications"}, security={{"bearerAuth":{}}},
     *   summary="Delete a price alert rule", @OA\Response(response=204, description="Deleted"))
     */
    public function destroyAlert(Request $request, PriceAlert $alert): JsonResponse
    {
        abort_unless($alert->user_id === $request->user()->getKey(), 403);

        $alert->delete();

        return ApiResponse::noContent();
    }

    /**
     * @OA\Post(path="/notifications/devices", tags={"Notifications"}, security={{"bearerAuth":{}}},
     *   summary="Register or refresh an FCM device token",
     *
     *   @OA\Response(response=200, description="Device registered"))
     */
    public function registerDevice(Request $request): JsonResponse
    {
        $data = $request->validate([
            'device_uuid' => ['required', 'string', 'max:64'],
            'fcm_token' => ['required', 'string', 'max:255'],
            'platform' => ['required', 'string', 'in:web,ios,android'],
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        $device = $request->user()->devices()->updateOrCreate(
            ['device_uuid' => $data['device_uuid']],
            $data + ['last_seen_at' => now()],
        );

        return ApiResponse::success(['id' => $device->getKey()], 'Device registered for push notifications.');
    }
}
