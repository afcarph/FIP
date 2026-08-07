<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Doe\Models\FuelPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FuelPrice
 */
class FuelPriceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'area' => $this->area,
            'province' => $this->province,
            'product' => $this->product,
            'fuel_code' => $this->fuel_code,
            // Null marks the area's overall row rather than a brand's, and the
            // flag saves every client re-deriving that from a null check.
            'brand' => $this->brand,
            'is_overall' => $this->brand === null,
            'min_price' => $this->min_price,
            'max_price' => $this->max_price,
            // Published by the DOE on the overall row, not computed here.
            'common_price' => $this->common_price,
            'report' => new FuelReportResource($this->whenLoaded('report')),
        ];
    }
}
