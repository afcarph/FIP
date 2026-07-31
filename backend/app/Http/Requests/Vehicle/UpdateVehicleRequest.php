<?php

declare(strict_types=1);

namespace App\Http\Requests\Vehicle;

use Illuminate\Validation\Rule;

class UpdateVehicleRequest extends StoreVehicleRequest
{
    public function rules(): array
    {
        $rules = parent::rules();

        // Everything is optional on update, and the plate uniqueness check
        // must ignore this vehicle's own row.
        foreach ($rules as $field => $constraints) {
            $rules[$field] = array_map(
                static fn ($rule) => $rule === 'required' ? 'sometimes' : $rule,
                $constraints,
            );
        }

        $rules['plate_number'] = [
            'sometimes', 'string', 'max:16',
            'regex:/^[A-Z0-9]{2,4}[\s-]?[A-Z0-9]{3,4}$/i',
            Rule::unique('vehicles', 'plate_number')
                ->ignore($this->route('vehicle')?->getKey())
                ->whereNull('deleted_at'),
        ];

        $rules['status'] = ['sometimes', 'string', 'in:active,in_maintenance,inactive,sold'];

        return $rules;
    }
}
