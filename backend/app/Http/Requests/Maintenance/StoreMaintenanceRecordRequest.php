<?php

declare(strict_types=1);

namespace App\Http\Requests\Maintenance;

use Illuminate\Foundation\Http\FormRequest;

class StoreMaintenanceRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'maintenance_type_id' => ['required', 'integer', 'exists:maintenance_types,id'],
            'performed_at' => ['required', 'date', 'before_or_equal:today'],
            'odometer' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'cost' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'vendor' => ['nullable', 'string', 'max:180'],
            'invoice_path' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
