<?php

declare(strict_types=1);

namespace App\Http\Requests\Vehicle;

use App\Domain\Fleet\Models\VehicleFuelReading;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFuelReadingRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The vehicle-level check is the policy's job; the controller runs it.
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'fuel_pct' => ['required', 'numeric', 'between:0,100'],
            // Optional: the service derives it from the vehicle's tank capacity
            // when omitted, which is the normal case for a gauge reading.
            'fuel_litres' => ['nullable', 'numeric', 'min:0', 'max:2000'],
            'recorded_at' => ['nullable', 'date', 'before_or_equal:now'],
            // `telematics` is accepted so a future gateway needs no API change,
            // but `simulated` is not: simulated data is written by the artisan
            // command, and allowing a client to mint it would let anyone forge
            // rows that the purge then silently deletes.
            'source' => ['nullable', 'string', Rule::in([
                VehicleFuelReading::SOURCE_MANUAL,
                VehicleFuelReading::SOURCE_TELEMATICS,
            ])],
            'fuel_purchase_id' => ['nullable', 'integer', 'exists:fuel_purchases,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'fuel_pct.between' => 'Fuel level must be a percentage between 0 and 100.',
            'recorded_at.before_or_equal' => 'A fuel reading cannot be dated in the future.',
        ];
    }
}
