<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Fleet\Services\FuelLevelService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class VehicleResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            // See DriverResource: the assignment screen matches the two.
            'company_id' => $this->company_id,
            'nickname' => $this->nickname,
            'display_name' => $this->display_name,
            'plate_number' => $this->plate_number,
            'vehicle_type' => $this->vehicle_type,
            'year' => $this->year,
            'color' => $this->color,
            'transmission' => $this->transmission,
            'make' => $this->whenLoaded('make', fn () => $this->make?->name),
            'model' => $this->whenLoaded('model', fn () => $this->model?->name),
            'fuel_type' => $this->whenLoaded('fuelType', fn () => [
                'id' => $this->fuelType?->id,
                'name' => $this->fuelType?->name,
                'code' => $this->fuelType?->code,
                'color_hex' => $this->fuelType?->color_hex,
            ]),
            'fleet' => $this->whenLoaded('fleet', fn () => $this->fleet ? ['id' => $this->fleet->id, 'name' => $this->fleet->name] : null),
            'tank_capacity' => $this->tank_capacity,
            'current_odometer' => $this->current_odometer,
            // Additive: existing clients ignore the block, and every field is
            // null on a vehicle nobody has reported a level for. `status` is
            // null rather than NORMAL in that case — no reading is not the same
            // as a healthy reading, and a dashboard that conflates them shows a
            // reassuring green for a vehicle it knows nothing about.
            'fuel' => [
                'current_percentage' => $this->current_fuel_pct,
                'current_litres' => $this->current_fuel_litres,
                'recorded_at' => $this->fuel_level_at?->toIso8601String(),
                'status' => app(FuelLevelService::class)->statusFor($this->current_fuel_pct),
                'is_stale' => $this->current_fuel_pct !== null
                    && app(FuelLevelService::class)->isStale($this->fuel_level_at),
            ],
            'efficiency' => [
                'baseline_km_per_litre' => $this->baseline_km_per_litre,
                'avg_km_per_litre' => $this->avg_km_per_litre,
                'deviation_pct' => $this->efficiencyDeviationPct(),
                'estimated_range_km' => $this->estimatedRangeKm(),
            ],
            'documents' => [
                'registration_expiry' => $this->registration_expiry?->toDateString(),
                'insurance_provider' => $this->insurance_provider,
                'insurance_expiry' => $this->insurance_expiry?->toDateString(),
                // Not `?->diffInDays(...) * -1`: the null-safe call yields null,
                // and null * -1 is 0 in PHP — so a vehicle with no registration
                // on file reported as expiring today, and the app showed a
                // compliance warning for a document that does not exist.
                'registration_expires_in_days' => $this->registration_expiry
                    ? $this->registration_expiry->diffInDays(now(), false) * -1
                    : null,
                'insurance_expires_in_days' => $this->insurance_expiry
                    ? $this->insurance_expiry->diffInDays(now(), false) * -1
                    : null,
            ],
            // `id` is the drivers-table key. `user_id` is added alongside it
            // because a client only ever knows who is signed in, and without
            // it there is no reliable way to answer "is this my vehicle?" —
            // matching on name would break on two drivers sharing one.
            // Additive: nothing that reads `id` or `name` is affected.
            'assigned_driver' => $this->whenLoaded('currentAssignment', fn () => $this->currentAssignment?->driver
                ? [
                    'id' => $this->currentAssignment->driver->id,
                    'user_id' => $this->currentAssignment->driver->user_id,
                    'name' => $this->currentAssignment->driver->full_name,
                ]
                : null),
            'maintenance' => $this->whenLoaded('maintenanceSchedules', fn () => $this->maintenanceSchedules
                ->whereIn('status', ['due_soon', 'overdue'])
                ->map(static fn ($s) => [
                    'service' => $s->type?->name,
                    'status' => $s->status,
                    'due_at' => $s->due_at?->toDateString(),
                ])->values()),
            'status' => $this->status,
            'photo_path' => $this->photo_path,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
