<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:80'],
            'last_name' => ['required', 'string', 'max:80'],
            'email' => ['required', 'email:rfc,dns', 'max:180', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9\s\-()]{7,32}$/'],
            'password' => [
                'required',
                'confirmed',
                Password::min((int) config('fip.security.password_min_length'))
                    ->mixedCase()
                    ->numbers()
                    ->symbols()
                    ->uncompromised(),
            ],
            'home_city_id' => ['nullable', 'integer', 'exists:cities,id'],
            'preferred_fuel_type_id' => ['nullable', 'integer', 'exists:fuel_types,id'],
            'accepts_terms' => ['accepted'],
            'device' => ['nullable', 'array'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.uncompromised' => 'That password has appeared in a known data breach. Please choose another.',
            'accepts_terms.accepted' => 'You must accept the terms of service to create an account.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }
}
