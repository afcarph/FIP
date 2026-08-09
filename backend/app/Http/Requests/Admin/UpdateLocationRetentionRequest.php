<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLocationRetentionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // The route already gates on the permission; the service enforces the
        // range. This request's job is only to insist the value is a whole
        // number of days before anything downstream has to guess.
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'days' => ['required', 'integer', 'min:1', 'max:'.config('fip.location.retention_max_days')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'days.min' => 'The retention period must be at least one day. Pruning cannot be disabled from here.',
            'days.max' => 'The retention period may not exceed :max days.',
        ];
    }
}
