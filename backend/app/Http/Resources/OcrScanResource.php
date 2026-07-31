<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OcrScanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status,
            'engine' => $this->engine,
            'overall_confidence' => $this->overall_confidence,
            'image_url' => $this->image_url,
            'station' => $this->whenLoaded('station', fn () => $this->station ? [
                'id' => $this->station->id,
                'name' => $this->station->name,
            ] : null),
            'lines' => collect($this->parsed_payload ?? [])->map(static fn (array $line) => [
                'label' => $line['label'] ?? null,
                'fuel_type_id' => $line['fuel_type_id'] ?? null,
                'fuel_type_code' => $line['fuel_type_code'] ?? null,
                'price' => $line['price'] ?? null,
                'confidence' => $line['confidence'] ?? null,
                'valid' => $line['valid'] ?? false,
                'rejection_reason' => $line['rejection_reason'] ?? null,
                'bbox' => $line['bbox'] ?? null,
            ])->all(),
            'raw_text' => $this->when($request->user()?->can('prices.moderate'), $this->raw_text),
            'error_message' => $this->error_message,
            'reviewed_at' => $this->reviewed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
