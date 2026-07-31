<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DriverResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'employee_no' => $this->employee_no,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'status' => $this->status,
            'licence' => [
                'number' => $this->licence_number,
                'type' => $this->licence_type,
                'expiry' => $this->licence_expiry?->toDateString(),
                'expires_in_days' => $this->licence_expiry?->diffInDays(now(), false) * -1,
            ],
            'scores' => [
                'safety' => $this->safety_score,
                'efficiency' => $this->efficiency_score,
            ],
            'fleet' => $this->whenLoaded('fleet', fn () => $this->fleet ? ['id' => $this->fleet->id, 'name' => $this->fleet->name] : null),
            'assigned_vehicle' => $this->whenLoaded('currentAssignment', fn () => $this->currentAssignment?->vehicle
                ? ['id' => $this->currentAssignment->vehicle->id, 'plate_number' => $this->currentAssignment->vehicle->plate_number]
                : null),
            'hired_at' => $this->hired_at?->toDateString(),
        ];
    }
}
