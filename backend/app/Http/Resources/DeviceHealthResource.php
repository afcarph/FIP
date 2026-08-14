<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\User\Models\UserDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A device as a fleet operator sees it.
 *
 * Separate from DeviceResource rather than a flag on it, because the two answer
 * to different rules. DeviceResource is what an owner sees about their own
 * handset; this is what a manager sees about equipment carried in their
 * vehicles, and the difference has to be visible in the code rather than
 * remembered.
 *
 * So: no device_uuid, no fcm_token, no biometric_key, no trust flag. The uuid
 * is the identifier the device authenticates with; a health dashboard has no
 * use for it and every reason not to spread it around.
 *
 * The driver is named because a flat battery needs somebody to ring. That is
 * the only reason, and it is why the email is included and nothing else is.
 *
 * @mixin UserDevice
 */
class DeviceHealthResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $batteryIsFresh = $this->hasFreshBattery();

        return [
            'id' => $this->id,
            'device_name' => $this->device_name,
            'platform' => $this->platform,
            'app_version' => $this->app_version,
            'os_version' => $this->os_version,

            'driver' => $this->whenLoaded('user', fn () => $this->user ? [
                'id' => $this->user->id,
                'name' => trim($this->user->first_name.' '.$this->user->last_name),
                'email' => $this->user->email,
            ] : null),

            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle ? [
                'id' => $this->vehicle->id,
                'plate_number' => $this->vehicle->plate_number,
                'display_name' => $this->vehicle->display_name,
            ] : null),

            /*
             * The reading and its trustworthiness travel together, always. A
             * client that renders `percentage` without checking `is_fresh`
             * shows yesterday's charge as today's, so `is_fresh` is not
             * optional detail — it is what makes the number mean anything.
             */
            'battery' => [
                'percentage' => $this->battery_percentage,
                'state' => $this->battery_state,
                'updated_at' => $this->battery_updated_at?->toIso8601String(),
                'is_fresh' => $batteryIsFresh,
                'is_charging' => $this->isCharging(),
                'is_low' => $this->hasLowBattery(),
            ],

            /*
             * Two different questions that look like one. `is_online` is
             * whether the server has heard from the device; `is_tracking` is
             * whether it is entitled to report positions at all. A device can
             * be online and not tracking — a revoked handset still talks to the
             * API — and reporting only one of them would mislead either way.
             */
            'is_online' => $this->isOnline(),
            'is_tracking' => $this->canReportLocation(),
            'is_revoked' => $this->isRevoked(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),

            'last_seen_at' => $this->last_seen_at?->toIso8601String(),

            'last_location' => $this->last_location_at !== null ? [
                'latitude' => $this->last_latitude,
                'longitude' => $this->last_longitude,
                'recorded_at' => $this->last_location_at->toIso8601String(),
            ] : null,

            'registered_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
