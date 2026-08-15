<?php

declare(strict_types=1);

namespace App\Http\Requests\Fleet;

use Illuminate\Foundation\Http\FormRequest;

class StoreDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;   // the policy decides; this only rejects guests
    }

    /**
     * Shape only. `user_id`, `fleet_id` and `company_id` all name records that
     * belong to somebody, so the controller re-checks each against the caller's
     * tenant — `exists` proves a row is there, never that it is yours.
     */
    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],

            // Optional: a driver record can exist before the person has a login,
            // which is how a fleet is entered before anybody is onboarded.
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
            'company_id' => ['sometimes', 'nullable', 'integer', 'exists:companies,id'],
            'fleet_id' => ['sometimes', 'nullable', 'integer', 'exists:fleets,id'],

            'employee_no' => ['sometimes', 'nullable', 'string', 'max:40'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'licence_number' => ['sometimes', 'nullable', 'string', 'max:40'],
            'licence_type' => ['sometimes', 'nullable', 'string', 'max:16'],
            'licence_expiry' => ['sometimes', 'nullable', 'date'],
            'hired_at' => ['sometimes', 'nullable', 'date'],
            'status' => ['sometimes', 'string', 'in:active,inactive,suspended'],
        ];
    }
}
