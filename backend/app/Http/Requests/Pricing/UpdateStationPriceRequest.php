<?php

declare(strict_types=1);

namespace App\Http\Requests\Pricing;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStationPriceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'prices' => ['required', 'array', 'min:1', 'max:12'],
            'prices.*.fuel_type_id' => ['required', 'integer', 'exists:fuel_types,id', 'distinct'],
            'prices.*.price' => ['required', 'numeric', 'min:0.01', 'max:999.99'],
        ];
    }

    public function messages(): array
    {
        return ['prices.*.fuel_type_id.distinct' => 'Each fuel type may appear only once.'];
    }
}
