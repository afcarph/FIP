<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Doe\Models\FuelReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin FuelReport
 */
class FuelReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'region' => $this->region,
            'coverage_start' => $this->coverage_start->toDateString(),
            'coverage_end' => $this->coverage_end->toDateString(),
            'coverage_label' => $this->coverage_label,
            // The week covered and the days prices were collected are
            // different, and the DOE states both.
            'monitoring_date' => $this->monitoring_date?->toDateString(),
            'publication_date' => $this->publication_date?->toDateString(),
            // Provenance. A user asking where a price came from gets the
            // document it came from, not a claim.
            'source_url' => $this->source_url,
            'checksum' => $this->checksum,
            'extractor' => $this->extractor,
            'quality' => $this->quality,
            'areas_count' => $this->areas_count,
            'rows_count' => $this->rows_count,
            'prices' => FuelPriceResource::collection($this->whenLoaded('prices')),
        ];
    }
}
