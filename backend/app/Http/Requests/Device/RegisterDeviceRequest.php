<?php

declare(strict_types=1);

namespace App\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;

class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            // The application's own installation identifier, not a hardware one.
            'device_uuid' => ['required', 'string', 'min:8', 'max:64', 'regex:/^[A-Za-z0-9._:-]+$/'],
            'platform' => ['required', 'string', 'in:ios,android,web'],
            'device_name' => ['nullable', 'string', 'max:120'],
            'app_version' => ['nullable', 'string', 'max:24'],
            'os_version' => ['nullable', 'string', 'max:32'],
            'fcm_token' => ['nullable', 'string', 'max:255'],
            /*
             * user_id and vehicle_id are deliberately not accepted. Ownership
             * comes from the authenticated session, and a vehicle is attached
             * through the association endpoint, which checks the caller may act
             * on that vehicle. Accepting either here would let a client claim
             * a truck by naming it.
             */
        ];
    }

    public function messages(): array
    {
        return [
            'device_uuid.regex' => 'The device identifier contains unexpected characters.',
        ];
    }
}
