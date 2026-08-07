<?php

declare(strict_types=1);

namespace App\Domain\Doe\Data;

use App\Domain\Doe\Models\DoeStationReview;
use Illuminate\Support\Carbon;

/**
 * One station's published prices on one date, as parsed from the payload.
 *
 * The transport shape, not a domain model. It exists between the parser and
 * the importer and is never persisted: prices become `station_prices` and
 * `fuel_price_history` rows through PriceService, and nothing else about a DOE
 * listing is stored unless it fails to match.
 *
 * Prices are keyed by `fuel_types.code`, resolved by the schema mapper, so
 * this object already speaks the platform's vocabulary.
 */
final readonly class DoeRecord
{
    /**
     * @param array<string, float> $prices fuel_types.code → pesos per litre
     */
    public function __construct(
        public ?string $company,
        public ?string $station,
        public ?string $address,
        public ?string $city,
        public ?string $province,
        public ?string $barangay,
        public ?float $latitude,
        public ?float $longitude,
        public Carbon $priceDate,
        public array $prices,
    ) {}

    /**
     * Identity of this listing, shared with the review queue.
     *
     * The same four fields, so a record that fails to match today lands on the
     * row a reviewer already saw yesterday rather than creating a new one.
     */
    public function fingerprint(): string
    {
        return DoeStationReview::fingerprintFor(
            $this->company,
            $this->station,
            $this->city,
            $this->barangay,
        );
    }

    /** A label for logs and the review queue. */
    public function describe(): string
    {
        $name = trim((string) ($this->station ?: $this->company));
        $place = trim((string) ($this->city ?: $this->province));

        return $place !== '' ? "{$name} — {$place}" : ($name ?: 'unnamed listing');
    }

    /**
     * Merge another reading of the same station and date into this one.
     *
     * The dashboard serves a station from more than one tile, and each tile
     * may carry a different subset of the grades. Taking the later record
     * wholesale would discard the prices only the earlier one knew.
     */
    public function mergedWith(self $other): self
    {
        return new self(
            company: $this->company ?? $other->company,
            station: $this->station ?? $other->station,
            address: $this->address ?? $other->address,
            city: $this->city ?? $other->city,
            province: $this->province ?? $other->province,
            barangay: $this->barangay ?? $other->barangay,
            latitude: $this->latitude ?? $other->latitude,
            longitude: $this->longitude ?? $other->longitude,
            priceDate: $this->priceDate,
            // This record's price wins where both have one; the other fills gaps.
            prices: $this->prices + $other->prices,
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'company' => $this->company,
            'station' => $this->station,
            'address' => $this->address,
            'city' => $this->city,
            'province' => $this->province,
            'barangay' => $this->barangay,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'price_date' => $this->priceDate->toDateString(),
            'prices' => $this->prices,
        ];
    }
}
