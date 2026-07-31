<?php

declare(strict_types=1);

namespace App\Http\Requests\Station;

use Illuminate\Foundation\Http\FormRequest;

class NearbyStationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;   // proximity search is open to guests
    }

    public function rules(): array
    {
        return [
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
            'radius_km' => ['nullable', 'numeric', 'min:0.1', 'max:'.config('fip.pricing.max_radius_km')],
            'fuel_type_id' => ['nullable', 'integer', 'exists:fuel_types,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
