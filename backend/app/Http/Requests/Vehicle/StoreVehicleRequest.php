<?php

declare(strict_types=1);

namespace App\Http\Requests\Vehicle;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreVehicleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'plate_number' => [
                'required', 'string', 'max:16',
                // PH plates: "ABC 1234", "NCR 1234", motorcycle "MC 5678".
                'regex:/^[A-Z0-9]{2,4}[\s-]?[A-Z0-9]{3,4}$/i',
                Rule::unique('vehicles', 'plate_number')->whereNull('deleted_at'),
            ],
            'nickname' => ['nullable', 'string', 'max:80'],
            'vehicle_type' => ['required', 'string', 'in:car,suv,van,motorcycle,tricycle,jeepney,truck,bus,trailer,ev'],
            'fuel_type_id' => ['required', 'integer', 'exists:fuel_types,id'],
            'make_id' => ['nullable', 'integer', 'exists:vehicle_makes,id'],
            'model_id' => ['nullable', 'integer', 'exists:vehicle_models,id'],
            'fleet_id' => ['nullable', 'integer', 'exists:fleets,id'],
            'vin' => ['nullable', 'string', 'max:32', 'alpha_num'],
            'engine_number' => ['nullable', 'string', 'max:32'],
            'year' => ['nullable', 'integer', 'min:1950', 'max:'.(date('Y') + 1)],
            'color' => ['nullable', 'string', 'max:32'],
            'transmission' => ['nullable', 'string', 'in:manual,automatic,cvt,dct'],
            'engine_displacement_cc' => ['nullable', 'integer', 'min:50', 'max:30000'],
            'tank_capacity' => ['nullable', 'numeric', 'min:1', 'max:2000'],
            'current_odometer' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'baseline_km_per_litre' => ['nullable', 'numeric', 'min:0.5', 'max:100'],
            'registration_expiry' => ['nullable', 'date'],
            'insurance_provider' => ['nullable', 'string', 'max:120'],
            'insurance_policy_no' => ['nullable', 'string', 'max:64'],
            'insurance_expiry' => ['nullable', 'date'],
            'photo_path' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('plate_number')) {
            $this->merge(['plate_number' => strtoupper(trim((string) $this->input('plate_number')))]);
        }
    }

    public function messages(): array
    {
        return [
            'plate_number.regex' => 'Enter a valid plate number, for example "ABC 1234".',
            'plate_number.unique' => 'That plate number is already registered.',
        ];
    }
}
