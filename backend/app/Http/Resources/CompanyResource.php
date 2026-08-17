<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\User\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A tenant.
 *
 * `subscription` is attached by the controller rather than computed here,
 * because it costs three counting queries and only the detail view needs it —
 * putting it in the resource would make a listing of fifty companies run a
 * hundred and fifty of them.
 *
 * @mixin Company
 */
class CompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'tin' => $this->tin,
            'industry' => $this->industry,
            'type' => $this->type,
            'address_line' => $this->address_line,
            'city_id' => $this->city_id,
            'contact_email' => $this->contact_email,
            'contact_phone' => $this->contact_phone,
            'subscription_tier' => $this->subscription_tier,
            'subscription_status' => $this->subscription_status,
            'trial_ends_at' => $this->trial_ends_at?->toIso8601String(),
            'is_active' => $this->is_active,

            'counts' => [
                'users' => $this->whenCounted('users'),
                'vehicles' => $this->whenCounted('vehicles'),
                'drivers' => $this->whenCounted('drivers'),
            ],

            // Present only where the controller resolved it.
            // Set by the listing, which counts usage in its own query. The
            // detail endpoint attaches the same shape via `additional`.
            'subscription' => $this->when(
                $this->resource->subscriptionReport !== null || ($this->additional['subscription'] ?? false),
                fn () => $this->resource->subscriptionReport ?? $this->additional['subscription'],
            ),

            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
