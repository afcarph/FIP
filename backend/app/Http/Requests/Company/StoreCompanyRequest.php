<?php

declare(strict_types=1);

namespace App\Http\Requests\Company;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;   // the policy decides; this only rejects guests
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:180'],
            'legal_name' => ['nullable', 'string', 'max:180'],
            'tin' => ['nullable', 'string', 'max:32'],
            'industry' => ['nullable', 'string', 'max:80'],
            'type' => ['nullable', 'string', 'max:24'],
            'address_line' => ['nullable', 'string', 'max:255'],
            'city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'contact_email' => ['nullable', 'email', 'max:180'],
            'contact_phone' => ['nullable', 'string', 'max:32'],

            /*
             * Constrained to the tiers that actually exist in config, not to a
             * free string. The column is a plain varchar, so an unrecognised
             * value would store happily and then be silently read as `free` by
             * SubscriptionLimitService's fallback — a tenant would appear to be
             * on a plan nobody sells and get the smallest allowance instead.
             */
            'subscription_tier' => [
                'nullable',
                'string',
                Rule::in(array_keys((array) config('fip.subscription.tiers', []))),
            ],

            'is_active' => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'subscription_tier.in' => 'That subscription tier does not exist.',
        ];
    }
}
