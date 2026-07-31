<?php

declare(strict_types=1);

namespace App\Http\Requests\Pricing;

use Illuminate\Foundation\Http\FormRequest;

class StorePriceReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'station_id' => ['required', 'integer', 'exists:gas_stations,id'],
            'report_type' => ['required', 'string', 'in:price,shortage,closure,long_queue,wrong_info'],
            // A price report is meaningless without the fuel type and figure.
            'fuel_type_id' => ['required_if:report_type,price', 'nullable', 'integer', 'exists:fuel_types,id'],
            'price' => ['required_if:report_type,price', 'nullable', 'numeric', 'min:0.01', 'max:999.99'],
            'photo_path' => ['nullable', 'string', 'max:255'],
            'comment' => ['nullable', 'string', 'max:500'],
            'latitude' => ['required_if:report_type,price', 'nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['required_if:report_type,price', 'nullable', 'numeric', 'between:-180,180'],
        ];
    }

    public function messages(): array
    {
        return [
            'latitude.required_if' => 'Location is required so we can confirm you are at the station.',
            'price.required_if' => 'Enter the price shown on the board.',
        ];
    }
}
