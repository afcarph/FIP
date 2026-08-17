<?php

declare(strict_types=1);

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Every field is `sometimes`: a PATCH that names one field must not blank the
 * rest, and "absent" has to stay distinguishable from "explicitly null".
 */
class UpdateCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:180'],
            'legal_name' => ['sometimes', 'nullable', 'string', 'max:180'],
            'tin' => ['sometimes', 'nullable', 'string', 'max:32'],
            'industry' => ['sometimes', 'nullable', 'string', 'max:80'],
            'type' => ['sometimes', 'nullable', 'string', 'max:24'],
            'address_line' => ['sometimes', 'nullable', 'string', 'max:255'],
            'city_id' => ['sometimes', 'nullable', 'integer', 'exists:cities,id'],
            'contact_email' => ['sometimes', 'nullable', 'email', 'max:180'],
            'contact_phone' => ['sometimes', 'nullable', 'string', 'max:32'],

            'subscription_tier' => [
                'sometimes',
                'string',
                Rule::in(array_keys((array) config('fip.subscription.tiers', []))),
            ],

            /*
             * Negotiated limits, for confirming an enterprise agreement. A
             * null value for a resource is a deliberate "no limit"; omitting
             * the key entirely leaves the plan's configured number in force.
             * Platform-only by virtue of the route — a tenant cannot reach
             * this endpoint at all.
             */
            'subscription_limits' => ['sometimes', 'array'],
            'subscription_limits.vehicles' => ['nullable', 'integer', 'min:0'],
            'subscription_limits.seats' => ['nullable', 'integer', 'min:0'],
            'subscription_limits.devices' => ['nullable', 'integer', 'min:0'],

            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'subscription_tier.in' => 'That subscription tier does not exist.',
        ];
    }
}
