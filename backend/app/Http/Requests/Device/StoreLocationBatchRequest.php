<?php

declare(strict_types=1);

namespace App\Http\Requests\Device;

use Illuminate\Foundation\Http\FormRequest;

class StoreLocationBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Shape only. The values are checked again in LocationIngestService, which
     * decides per point rather than per request: one implausible fix must not
     * reject the good positions queued behind it.
     */
    public function rules(): array
    {
        return [
            'points' => ['required', 'array', 'min:1', 'max:'.(int) config('fip.location.max_batch_size')],
            'points.*.latitude' => ['required', 'numeric', 'between:-90,90'],
            'points.*.longitude' => ['required', 'numeric', 'between:-180,180'],
            'points.*.recorded_at' => ['required', 'date'],
            'points.*.accuracy_m' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'points.*.altitude_m' => ['nullable', 'numeric', 'between:-500,10000'],
            'points.*.speed_kph' => ['nullable', 'numeric', 'min:0', 'max:400'],
            'points.*.heading_deg' => ['nullable', 'numeric', 'between:0,360'],
        ];
    }
}
