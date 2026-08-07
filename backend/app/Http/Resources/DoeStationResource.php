<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Doe\Models\DoeStation;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DoeStation
 */
class DoeStationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->label,
            'company' => $this->company,
            'region' => $this->region,
            'province' => $this->province,
            'city' => $this->city,
            'barangay' => $this->barangay,
            'address' => $this->display_address,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            // Whether this station appeared in the most recent run. A station
            // the DOE has stopped publishing is not deleted — it may reappear —
            // but a client showing it as current would be wrong.
            'last_seen_at' => $this->last_seen_at?->toIso8601String(),
            'prices' => DoePriceResource::collection($this->whenLoaded('prices')),
        ];
    }
}
