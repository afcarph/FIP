<?php

declare(strict_types=1);

namespace App\Http\Requests\Station;

use Illuminate\Foundation\Http\FormRequest;

class StoreStationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'brand_id' => [$required, 'integer', 'exists:brands,id'],
            'name' => [$required, 'string', 'max:180'],
            'address_line' => [$required, 'string', 'max:255'],
            'city_id' => [$required, 'integer', 'exists:cities,id'],
            'latitude' => [$required, 'numeric', 'between:-90,90'],
            'longitude' => [$required, 'numeric', 'between:-180,180'],
            'postal_code' => ['nullable', 'string', 'max:12'],
            'phone' => ['nullable', 'string', 'max:32'],
            'is_24_hours' => ['nullable', 'boolean'],
            'has_ev_charging' => ['nullable', 'boolean'],
            'operator_id' => ['nullable', 'integer', 'exists:companies,id'],
            'status' => ['sometimes', 'string', 'in:active,temporarily_closed,permanently_closed,pending_review'],
            'amenities' => ['nullable', 'array'],
            'amenities.*' => ['integer', 'exists:amenities,id'],
            'payment_methods' => ['nullable', 'array'],
            'payment_methods.*' => ['integer', 'exists:payment_methods,id'],
        ];
    }
}
