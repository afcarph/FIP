<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Pricing\Models\StationPrice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'slug' => $this->slug,
            'brand' => $this->whenLoaded('brand', fn () => [
                'id' => $this->brand->id,
                'name' => $this->brand->name,
                'code' => $this->brand->code,
                'color_hex' => $this->brand->color_hex,
                'logo_path' => $this->brand->logo_path,
            ]),
            'address' => [
                'line' => $this->address_line,
                'city' => $this->whenLoaded('city', fn () => $this->city->name),
                'region' => $this->whenLoaded('city', fn () => $this->city->province?->region?->name),
                'postal_code' => $this->postal_code,
            ],
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            // Present only on proximity queries.
            'distance_km' => $this->when(
                isset($this->distance_m),
                fn () => round(((float) $this->distance_m) / 1000, 2),
            ),
            'phone' => $this->phone,
            'is_24_hours' => $this->is_24_hours,
            'has_ev_charging' => $this->has_ev_charging,
            'status' => $this->status,
            'is_verified' => $this->verified_at !== null,
            'rating' => ['average' => (float) $this->rating_avg, 'count' => $this->rating_count],
            'prices' => $this->whenLoaded('prices', fn () => $this->prices->map(static fn (StationPrice $price) => [
                'fuel_type_id' => $price->fuel_type_id,
                'fuel_type' => $price->fuelType?->name,
                'fuel_code' => $price->fuelType?->code,
                'color_hex' => $price->fuelType?->color_hex,
                'price' => (float) $price->price,
                'previous_price' => $price->previous_price !== null ? (float) $price->previous_price : null,
                'change_amount' => (float) $price->change_amount,
                'trend' => $price->trend(),
                'source' => $price->source,
                'confidence' => $price->confidence,
                'is_stale' => $price->isStale(),
                'effective_at' => $price->effective_at?->toIso8601String(),
            ])->values()),
            'amenities' => $this->whenLoaded('amenities', fn () => $this->amenities->map->only(['id', 'code', 'name', 'icon'])),
            'payment_methods' => $this->whenLoaded('paymentMethods', fn () => $this->paymentMethods->map->only(['id', 'code', 'name', 'icon'])),
            'hours' => $this->whenLoaded('hours', fn () => $this->hours->map->only(['day_of_week', 'opens_at', 'closes_at', 'is_closed'])),
            'photos' => $this->whenLoaded('photos', fn () => $this->photos->where('status', 'approved')->map(static fn ($photo) => [
                'id' => $photo->id,
                'url' => $photo->url,
                'caption' => $photo->caption,
                'is_primary' => $photo->is_primary,
            ])->values()),
        ];
    }
}
