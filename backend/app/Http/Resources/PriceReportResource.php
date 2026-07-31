<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PriceReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'report_type' => $this->report_type,
            'station' => $this->whenLoaded('station', fn () => [
                'id' => $this->station?->id,
                'name' => $this->station?->name,
                'brand' => $this->station?->brand?->name,
                'city' => $this->station?->city?->name,
            ]),
            'fuel_type' => $this->whenLoaded('fuelType', fn () => $this->fuelType?->name),
            'price' => $this->price !== null ? (float) $this->price : null,
            'comment' => $this->comment,
            'photo_path' => $this->photo_path,
            'distance_m' => $this->distance_m,
            'trust_score' => $this->trust_score,
            'status' => $this->status,
            'votes' => ['up' => $this->upvotes, 'down' => $this->downvotes, 'score' => $this->communityScore()],
            'reporter' => $this->whenLoaded('user', fn () => [
                'id' => $this->user?->id,
                // Surnames are abbreviated in public listings.
                'name' => $this->user ? $this->user->first_name.' '.mb_substr((string) $this->user->last_name, 0, 1).'.' : null,
            ]),
            'rejection_reason' => $this->rejection_reason,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
