<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Fleet\Models\DeviceLocation;
use App\Domain\Fleet\Services\LocationRetentionService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateLocationRetentionRequest;
use App\Support\Http\ApiResponse;
use Illuminate\Http\JsonResponse;

/**
 * @OA\Tag(name="Admin — Settings", description="Platform configuration held in the database")
 */
class SettingsController extends Controller
{
    public function __construct(private readonly LocationRetentionService $retention) {}

    /**
     * @OA\Get(path="/admin/settings/privacy", tags={"Admin — Settings"}, security={{"bearerAuth":{}}},
     *   summary="Read privacy and data-retention settings",
     *
     *   @OA\Response(response=200, description="Current settings"))
     */
    public function privacy(): JsonResponse
    {
        return ApiResponse::success([
            'location_retention' => $this->retention->describe() + [
                // What the current period actually implies, so the decision is
                // made against a number rather than an abstraction.
                'stored_rows' => DeviceLocation::query()->count(),
                'rows_beyond_retention' => $this->rowsBeyondRetention(),
            ],
        ]);
    }

    /**
     * @OA\Put(path="/admin/settings/privacy/location-retention", tags={"Admin — Settings"},
     *   security={{"bearerAuth":{}}}, summary="Set the vehicle location retention period",
     *
     *   @OA\RequestBody(required=true, @OA\JsonContent(required={"days"},
     *
     *     @OA\Property(property="days", type="integer", example=30))),
     *
     *   @OA\Response(response=200, description="Updated"),
     *   @OA\Response(response=403, description="Requires settings.manage"))
     */
    public function updateLocationRetention(UpdateLocationRetentionRequest $request): JsonResponse
    {
        $this->retention->update($request->integer('days'), $request->user()?->getKey());

        return ApiResponse::success(
            $this->retention->describe(),
            'Location retention period updated.',
        );
    }

    /**
     * How much history the next prune would remove at the current period.
     *
     * Shown before the administrator commits, because "30 days" and "this will
     * delete 41,000 rows tonight" are not the same information.
     */
    private function rowsBeyondRetention(): int
    {
        $days = $this->retention->days();

        if ($days <= 0) {
            return 0;
        }

        return DeviceLocation::query()->where('recorded_at', '<', now()->subDays($days))->count();
    }
}
