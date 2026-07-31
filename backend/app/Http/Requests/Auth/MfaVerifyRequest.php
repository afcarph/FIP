<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

class MfaVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'challenge_token' => ['required', 'string', 'size:64'],
            // Six digits for TOTP, or an eleven-character recovery code.
            'code' => ['required', 'string', 'max:16'],
            'device' => ['nullable', 'array'],
        ];
    }
}
