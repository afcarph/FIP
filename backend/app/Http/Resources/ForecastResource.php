<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ForecastResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'fuel_type' => [
                'id' => $this->fuel_type_id,
                'name' => $this->fuelType?->name,
                'code' => $this->fuelType?->code,
                'color_hex' => $this->fuelType?->color_hex,
            ],
            'forecast_for' => $this->forecast_for?->toDateString(),
            'generated_at' => $this->generated_at?->toIso8601String(),
            'direction' => $this->direction,
            'change_amount' => $this->change_amount,
            'predicted_price' => $this->predicted_price,
            'range' => ['lower' => $this->lower_bound, 'upper' => $this->upper_bound],
            'confidence' => $this->confidence,
            'confidence_label' => $this->confidenceLabel(),
            'is_confident' => $this->isConfident(),
            'label' => $this->label(),
            'narrative' => $this->narrative,
            'drivers' => $this->drivers ?? [],
            // Populated once the DOE publishes the real adjustment.
            'actual_change' => $this->actual_change,
            'absolute_error' => $this->absolute_error,
            'was_correct' => $this->when($this->actual_change !== null, fn () => $this->directionWasCorrect()),
        ];
    }

    private function confidenceLabel(): string
    {
        return match (true) {
            $this->confidence >= 0.85 => 'high',
            $this->confidence >= 0.65 => 'moderate',
            default => 'low',
        };
    }

    private function directionWasCorrect(): bool
    {
        $actual = match (true) {
            $this->actual_change > 0.0001 => 'increase',
            $this->actual_change < -0.0001 => 'rollback',
            default => 'no_change',
        };

        return $actual === $this->direction;
    }
}
