<?php

declare(strict_types=1);

namespace App\Http\Requests\Fleet;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Every field is `sometimes`, so a PATCH naming one field cannot blank the rest.
 *
 * `company_id` is deliberately absent: moving a driver between tenants is not
 * an edit, and allowing it here would let a fleet manager hand their driver to
 * another company — the same hole that had to be closed on user administration.
 */
class UpdateDriverRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['sometimes', 'string', 'max:80'],
            'last_name' => ['sometimes', 'string', 'max:80'],
            'user_id' => ['sometimes', 'nullable', 'integer', 'exists:users,id'],
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
