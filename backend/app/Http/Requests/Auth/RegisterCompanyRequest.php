<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class RegisterCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /*
             * Validated against the configured tiers, so a plan nobody set up
             * cannot be registered on. This is a shape check only — what the
             * plan *means* is decided server-side in
             * CompanyRegistrationService, and no limit is ever read from this
             * payload.
             */
            'plan' => ['required', 'string', Rule::in(array_keys((array) config('fip.subscription.tiers', [])))],

            'company.name' => ['required', 'string', 'max:180'],
            'company.legal_name' => ['nullable', 'string', 'max:180'],
            'company.tin' => ['nullable', 'string', 'max:32'],
            'company.industry' => ['nullable', 'string', 'max:80'],
            'company.type' => ['nullable', 'string', 'max:24'],
            'company.address_line' => ['nullable', 'string', 'max:255'],
            'company.city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'company.contact_email' => ['nullable', 'email:rfc', 'max:180'],
            'company.contact_phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9\s\-()]{7,32}$/'],

            'admin.first_name' => ['required', 'string', 'max:80'],
            'admin.last_name' => ['required', 'string', 'max:80'],
            // See RegisterRequest: `rfc` and not `dns`, so a DNS hiccup cannot
            // reject a valid address on the signup path.
            'admin.email' => ['required', 'email:rfc', 'max:180', 'unique:users,email'],
            'admin.phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9\s\-()]{7,32}$/'],
            'admin.password' => [
                'required',
                'confirmed',
                Password::min((int) config('fip.security.password_min_length'))
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
                    ->uncompromised(),
            ],

            'accepts_terms' => ['accepted'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'plan.in' => 'That plan does not exist.',
            'company.name.required' => 'Your company needs a name.',
            'admin.email.unique' => 'An account with that email already exists.',
            'accepts_terms.accepted' => 'You must accept the terms to register.',
        ];
    }
}
