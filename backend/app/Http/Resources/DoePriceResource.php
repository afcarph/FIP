<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Doe\Models\DoePrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DoePrice
 */
class DoePriceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $fuels = [];

        foreach (DoePrice::FUELS as $fuel) {
            $value = $this->{$fuel};

            // Every grade is present in the response, including the ones this
            // station does not sell, so a client can render a stable table
            // without checking which keys exist. Null means not sold or not
            // published — it never means zero.
            $fuels[$fuel] = [
                'label' => DoePrice::FUEL_LABELS[$fuel],
                'price' => $value !== null ? round((float) $value, 2) : null,
            ];
        }

        return [
            'id' => $this->id,
            'station_id' => $this->station_id,
            'price_date' => $this->price_date->toDateString(),
            'fuels' => $fuels,
            'scraped_at' => $this->scraped_at?->toIso8601String(),
            'station' => new DoeStationResource($this->whenLoaded('station')),
        ];
    }
}
