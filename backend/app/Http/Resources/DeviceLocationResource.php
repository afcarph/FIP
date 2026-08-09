<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeviceLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicle_id,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'accuracy_m' => $this->accuracy_m,
            'altitude_m' => $this->altitude_m,
            'speed_kph' => $this->speed_kph,
            'heading_deg' => $this->heading_deg,
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            // Both clocks are exposed. The gap between them tells a client
            // whether it is looking at a live position or one that sat in an
            // offline queue, which a single timestamp cannot express.
            'received_at' => $this->received_at?->toIso8601String(),
        ];
    }
}
