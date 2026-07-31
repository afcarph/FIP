<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:180'],
            'password' => ['required', 'string', 'max:255'],
            'device' => ['nullable', 'array'],
            'device.device_uuid' => ['required_with:device', 'string', 'max:64'],
            'device.device_name' => ['nullable', 'string', 'max:120'],
            'device.platform' => ['nullable', 'string', 'in:web,ios,android'],
            'device.fcm_token' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => mb_strtolower(trim((string) $this->input('email')))]);
    }
}
