<?php

declare(strict_types=1);

namespace App\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;

class ReportDeviceHealthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * A device reporting about itself.
     *
     * `battery_percentage` is bounded at both ends rather than merely being an
     * integer: iOS returns -1 when the battery level is unavailable, and an
     * unclamped write would store a negative charge and light up every
     * low-battery filter in the fleet. A device that cannot read its own
     * battery should send nothing, and gets a 422 if it sends nonsense.
     *
     * `recorded_at` is optional and is what the *device* believed the time to
     * be. The service clamps it, for the same reason location readings are
     * clamped: a phone with a wrong clock must not appear to have reported
     * from the future.
     */
    public function rules(): array
    {
        return [
            'battery_percentage' => ['required', 'integer', 'between:0,100'],

            // The platforms disagree about what states exist, so the set is
            // the intersection that both can actually report. `unknown` is
            // explicit rather than implied by absence: "the device told us it
            // could not tell" and "the device never said" are different facts.
            'battery_state' => ['required', 'string', 'in:charging,discharging,full,unknown'],

            'recorded_at' => ['sometimes', 'date'],
        ];
    }

    public function messages(): array
    {
        return [
            'battery_percentage.between' => 'A battery percentage must be between 0 and 100.',
            'battery_state.in' => 'Unknown battery state.',
        ];
    }
}
