<?php

declare(strict_types=1);

namespace App\Domain\Doe\Data;

use App\Domain\Pricing\Services\PriceService;
use App\Domain\Station\Models\GasStation;
use Illuminate\Support\Carbon;

/**
 * One price, matched to a platform station and ready for PriceService.
 *
 * The last stop before {@see PriceService::recordPrice()}:
 * its fields are that method's arguments. Nothing here decides anything —
 * plausibility, whether a lower-confidence source may overwrite a fresher one,
 * and the history row are all PriceService's, and the importer does not
 * duplicate any of it.
 */
final readonly class PriceData
{
    /**
     * @param float $confidence how sure the *matcher* was this is the right
     *                          station. It is passed straight to
     *                          PriceService, which uses it to decide whether
     *                          this reading may supersede the stored one —
     *                          so a fuzzy match cannot overwrite an
     *                          operator's own price.
     */
    public function __construct(
        public GasStation $station,
        public int $fuelTypeId,
        public string $fuelCode,
        public float $price,
        public Carbon $effectiveAt,
        public float $confidence = 1.0,
        public string $source = self::SOURCE,
    ) {}

    /**
     * PriceService already documents this value in its source enum, and treats
     * it as authoritative enough to stamp `verified_at`.
     */
    public const SOURCE = 'doe';

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'station_id' => $this->station->getKey(),
            'fuel_type_id' => $this->fuelTypeId,
            'fuel_code' => $this->fuelCode,
            'price' => $this->price,
            'effective_at' => $this->effectiveAt->toIso8601String(),
            'confidence' => $this->confidence,
            'source' => $this->source,
        ];
    }
}
