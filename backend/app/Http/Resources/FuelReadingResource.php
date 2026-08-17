<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FuelReadingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fuel_pct' => $this->fuel_pct,
            'fuel_litres' => $this->fuel_litres,
            // Signed change from the preceding reading. Null on the first one,
            // which is a genuine absence rather than a zero — a series that
            // renders it as 0 draws a flat segment that never happened.
            'delta_pct' => $this->delta_pct,
            'source' => $this->source,
            'recorded_at' => $this->recorded_at?->toIso8601String(),
            // Set when a rise is explained by a recorded fill-up, so a client
            // can mark a refill differently from an unexplained gain.
            'fuel_purchase_id' => $this->fuel_purchase_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
