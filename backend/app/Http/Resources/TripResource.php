<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Expense\Models\Trip;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Trip
 */
class TripResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // The reference is what an operator says out loud; the row id is
            // only here because the client needs something to build URLs from.
            'reference_no' => $this->reference_no,
            'status' => $this->status,

            /*
             * The transitions this trip can actually make, computed from the
             * same table the server enforces. The screen renders its buttons
             * from this rather than reimplementing the state machine, so the
             * two cannot drift into offering an action that would be refused.
             */
            'can' => Trip::TRANSITIONS[$this->status] ?? [],

            'vehicle' => $this->whenLoaded('vehicle', fn () => $this->vehicle ? [
                'id' => $this->vehicle->id,
                'plate_number' => $this->vehicle->plate_number,
            ] : null),

            'driver' => $this->whenLoaded('driver', fn () => $this->driver ? [
                'id' => $this->driver->id,
                'name' => $this->driver->full_name,
            ] : null),

            'origin' => $this->origin_label,
            'destination' => $this->destination_label,
            'purpose' => $this->purpose,
            'notes' => $this->notes,

            'odometer' => [
                'start' => $this->odometer_start,
                'end' => $this->odometer_end,
            ],
            'distance_km' => $this->distance_km,

            'timeline' => [
                'scheduled_for' => $this->scheduled_for?->toIso8601String(),
                'created_at' => $this->created_at?->toIso8601String(),
                'dispatched_at' => $this->dispatched_at?->toIso8601String(),
                'started_at' => $this->started_at?->toIso8601String(),
                'ended_at' => $this->ended_at?->toIso8601String(),
                'cancelled_at' => $this->cancelled_at?->toIso8601String(),
            ],

            'cancellation_reason' => $this->cancellation_reason,
            'created_by' => $this->whenLoaded('creator', fn () => $this->creator?->full_name),
        ];
    }
}
