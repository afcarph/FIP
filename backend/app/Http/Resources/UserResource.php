<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'initials' => $this->initials,
            'email' => $this->email,
            'phone' => $this->phone,
            'avatar_url' => $this->avatar_path,
            'status' => $this->status,
            'locale' => $this->locale,
            'timezone' => $this->timezone,
            'email_verified' => $this->email_verified_at !== null,
            'mfa_enabled' => (bool) $this->mfa_enabled,
            'biometric_enabled' => (bool) $this->biometric_enabled,
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'company' => $this->whenLoaded('company', fn () => [
                'id' => $this->company->id,
                'name' => $this->company->name,
                'type' => $this->company->type,
                'subscription_tier' => $this->company->subscription_tier,
            ]),
            'preferences' => $this->whenLoaded('preferences', fn () => [
                'theme' => $this->preferences->theme,
                'preferred_fuel_type_id' => $this->preferences->preferred_fuel_type_id,
                'alert_radius_km' => (float) $this->preferences->alert_radius_km,
                'notify_price_alerts' => $this->preferences->notify_price_alerts,
                'notify_maintenance' => $this->preferences->notify_maintenance,
                'notify_ai_insights' => $this->preferences->notify_ai_insights,
            ]),
            'roles' => $this->whenLoaded('roles', fn () => $this->roles->pluck('name')),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
