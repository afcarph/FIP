<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class BiometricLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'device_uuid' => ['required', 'string', 'max:64'],
            'nonce' => ['required', 'string', 'size:64'],
            'signature' => ['required', 'string', 'max:1024'],
        ];
    }

    /**
     * The nonce must be the one this server issued for this device and must
     * not have been used before — otherwise a captured signature is replayable.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $key = "biometric:nonce:{$this->input('device_uuid')}";
                $issued = cache()->pull($key);

                if ($issued === null || ! hash_equals($issued, (string) $this->input('nonce'))) {
                    $validator->errors()->add('nonce', 'This sign-in challenge is invalid or has expired.');
                }
            },
        ];
    }
}
