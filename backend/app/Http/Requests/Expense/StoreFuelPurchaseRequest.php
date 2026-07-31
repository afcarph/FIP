<?php

declare(strict_types=1);

namespace App\Http\Requests\Expense;

use Illuminate\Foundation\Http\FormRequest;

class StoreFuelPurchaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'vehicle_id' => ['required', 'integer', 'exists:vehicles,id'],
            'fuel_type_id' => ['nullable', 'integer', 'exists:fuel_types,id'],
            'station_id' => ['nullable', 'integer', 'exists:gas_stations,id'],
            'driver_id' => ['nullable', 'integer', 'exists:drivers,id'],
            'litres' => ['required', 'numeric', 'min:0.001', 'max:5000'],
            'price_per_litre' => ['required', 'numeric', 'min:0.01', 'max:999.99'],
            'total_cost' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'odometer' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'is_full_tank' => ['nullable', 'boolean'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'receipt_path' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:255'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'purchased_at' => ['required', 'date', 'before_or_equal:now', 'after:2000-01-01'],
        ];
    }

    public function messages(): array
    {
        return [
            'purchased_at.before_or_equal' => 'A fill-up cannot be dated in the future.',
            'litres.max' => 'That volume looks wrong — check the units.',
        ];
    }
}
