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
            // Exposed so a client pairing drivers with vehicles can tell which
            // pairings the API will accept. A platform administrator belongs to
            // no company, so their driver and vehicle lists are unscoped and can
            // otherwise offer a combination that is refused on submit.
            'company_id' => $this->company_id,
            'employee_no' => $this->employee_no,
            'full_name' => $this->full_name,
            'phone' => $this->phone,
            'status' => $this->status,
            'licence' => [
                'number' => $this->licence_number,
                'type' => $this->licence_type,
                'expiry' => $this->licence_expiry?->toDateString(),
                // null * -1 is 0 in PHP, so a driver with no licence expiry on
                // file read as expiring today. See VehicleResource.
                'expires_in_days' => $this->licence_expiry
                    ? $this->licence_expiry->diffInDays(now(), false) * -1
                    : null,
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
