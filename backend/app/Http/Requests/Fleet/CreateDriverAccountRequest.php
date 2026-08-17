<?php

declare(strict_types=1);

namespace App\Http\Requests\Fleet;

use Illuminate\Foundation\Http\FormRequest;

class CreateDriverAccountRequest extends FormRequest
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
             * The email is all this takes. The name comes from the driver
             * record, the company comes from the driver, the role is fixed,
             * and the password is generated — so there is nothing else for a
             * caller to get wrong or to smuggle in.
             */
            'email' => ['required', 'email:rfc', 'max:180', 'unique:users,email'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:32', 'regex:/^\+?[0-9\s\-()]{7,32}$/'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'An account with that email already exists.',
        ];
    }
}
