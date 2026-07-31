<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FuelPurchaseResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'purchased_at' => $this->purchased_at?->toIso8601String(),
            'vehicle' => $this->whenLoaded('vehicle', fn () => [
                'id' => $this->vehicle?->id,
                'plate_number' => $this->vehicle?->plate_number,
                'name' => $this->vehicle?->display_name,
            ]),
            'station' => $this->whenLoaded('station', fn () => $this->station ? [
                'id' => $this->station->id,
                'name' => $this->station->name,
                'brand' => $this->station->brand?->name,
            ] : null),
            'fuel_type' => $this->whenLoaded('fuelType', fn () => $this->fuelType?->name),
            'litres' => $this->litres,
            'price_per_litre' => $this->price_per_litre,
            'total_cost' => $this->total_cost,
            'odometer' => $this->odometer,
            'distance_since_last' => $this->distance_since_last,
            'km_per_litre' => $this->km_per_litre,
            'cost_per_km' => $this->cost_per_km,
            'is_full_tank' => $this->is_full_tank,
            'notes' => $this->notes,
            'anomaly_score' => $this->anomaly_score,
            'is_flagged' => $this->anomaly_score !== null
                && $this->anomaly_score >= (float) config('fip.fraud.score_threshold'),
        ];
    }
}
