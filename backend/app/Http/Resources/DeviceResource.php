<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'device_uuid' => $this->device_uuid,
            'device_name' => $this->device_name,
            'platform' => $this->platform,
            'app_version' => $this->app_version,
            'os_version' => $this->os_version,
            'is_trusted' => $this->is_trusted,
            'is_revoked' => $this->isRevoked(),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'can_report_location' => $this->canReportLocation(),
            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle ? [
                'id' => $this->vehicle->id,
                'plate_number' => $this->vehicle->plate_number,
                'display_name' => $this->vehicle->display_name,
            ] : null),
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'last_location' => $this->last_location_at !== null ? [
                'latitude' => $this->last_latitude,
                'longitude' => $this->last_longitude,
                'recorded_at' => $this->last_location_at->toIso8601String(),
            ] : null,
            'created_at' => $this->created_at?->toIso8601String(),
            /*
             * fcm_token and biometric_key are never serialised. The first is a
             * push credential and the second is already $hidden on the model;
             * neither has any business in a device listing.
             */
        ];
    }
}
