<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Models\FuelPriceHistory;
use App\Domain\Pricing\Models\PriceAdvisory;
use App\Domain\Pricing\Models\StationPrice;
use App\Domain\Pricing\Repositories\PriceRepository;
use App\Domain\Station\Models\GasStation;
use App\Jobs\EvaluatePriceAlerts;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The single write path for live pump prices.
 *
 * Every mutation — operator edit, DOE import, approved crowd report, accepted
 * OCR line — funnels through {@see recordPrice()} so that four invariants
 * always hold together:
 *
 *   1. `station_prices` holds exactly one current row per station × fuel type;
 *   2. `fuel_price_history` gains an immutable archive row;
 *   3. cached reads for the affected station/city are invalidated;
 *   4. standing price alerts are re-evaluated asynchronously.
 */
final readonly class PriceService
{
    public function __construct(private PriceRepository $prices) {}

    /**
     * Upsert the live price and archive the movement.
     *
     * @param  string  $source  operator|doe|crowd|ocr|import|ai_estimate
     */
    public function recordPrice(
        GasStation $station,
        int $fuelTypeId,
        float $price,
        string $source = 'operator',
        float $confidence = 1.0,
        ?int $reportedBy = null,
        ?\DateTimeInterface $effectiveAt = null,
    ): StationPrice {
        $this->assertPlausible($price);

        $effectiveAt ??= now();

        $stationPrice = DB::transaction(function () use ($station, $fuelTypeId, $price, $source, $confidence, $reportedBy, $effectiveAt): StationPrice {
            /** @var StationPrice|null $existing */
            $existing = StationPrice::query()
                ->where('station_id', $station->getKey())
                ->where('fuel_type_id', $fuelTypeId)
                ->lockForUpdate()
                ->first();

            // A lower-confidence source must not overwrite a fresher, more
            // authoritative price (e.g. a crowd guess beating an operator feed).
            if ($existing !== null && ! $this->supersedes($existing, $source, $confidence, $effectiveAt)) {
                return $existing;
            }

            $attributes = [
                'price' => $price,
                'previous_price' => $existing?->price,
                'source' => $source,
                'confidence' => $confidence,
                'effective_at' => $effectiveAt,
                'reported_by' => $reportedBy,
                'verified_at' => in_array($source, ['operator', 'doe', 'import'], true) ? now() : null,
            ];

            $record = $existing === null
                ? StationPrice::create($attributes + ['station_id' => $station->getKey(), 'fuel_type_id' => $fuelTypeId])
                : tap($existing)->update($attributes);

            FuelPriceHistory::create([
                'station_id' => $station->getKey(),
                'fuel_type_id' => $fuelTypeId,
                'region_id' => $station->city?->province?->region_id,
                'price' => $price,
                'source' => $source,
                'recorded_on' => $effectiveAt->format('Y-m-d'),
                'recorded_at' => $effectiveAt,
            ]);

            return $record;
        });

        $this->flushCaches($station, $fuelTypeId);

        EvaluatePriceAlerts::dispatch($station->getKey(), $fuelTypeId, $price)->onQueue('notifications');

        return $stationPrice;
    }

    /**
     * Apply a DOE weekly adjustment across every station selling the fuel
     * type, optionally scoped to a region. Stations without a recorded price
     * are skipped — an adjustment is a delta, not an absolute quote.
     *
     * @return int number of stations updated
     */
    public function applyAdvisory(PriceAdvisory $advisory): int
    {
        if ($advisory->direction === 'no_change' || (float) $advisory->change_amount === 0.0) {
            return 0;
        }

        $updated = 0;

        StationPrice::query()
            ->where('fuel_type_id', $advisory->fuel_type_id)
            ->when($advisory->region_id !== null, fn ($q) => $q->whereHas(
                'station.city.province',
                fn ($p) => $p->where('region_id', $advisory->region_id),
            ))
            ->with('station.city.province')
            ->chunkById(200, function ($chunk) use ($advisory, &$updated): void {
                foreach ($chunk as $current) {
                    if ($current->station === null) {
                        continue;
                    }

                    $this->recordPrice(
                        station: $current->station,
                        fuelTypeId: $advisory->fuel_type_id,
                        price: round((float) $current->price + (float) $advisory->change_amount, 4),
                        source: 'doe',
                        confidence: 0.95,
                        effectiveAt: $advisory->effective_at,
                    );

                    $updated++;
                }
            });

        Log::channel('audit')->info('DOE advisory applied', [
            'advisory_id' => $advisory->getKey(),
            'fuel_type_id' => $advisory->fuel_type_id,
            'change' => (float) $advisory->change_amount,
            'stations_updated' => $updated,
        ]);

        return $updated;
    }

    /** Cached comparison matrix — the most-hit read on the platform. */
    public function comparison(?int $cityId = null): array
    {
        return Cache::remember(
            "prices:comparison:".($cityId ?? 'all'),
            now()->addMinutes(10),
            fn () => $this->prices->comparisonMatrix($cityId),
        );
    }

    public function trend(int $fuelTypeId, int $days = 90): array
    {
        return Cache::remember(
            "prices:trend:{$fuelTypeId}:{$days}",
            now()->addHour(),
            fn () => $this->prices->nationalTrend($fuelTypeId, $days),
        );
    }

    public function heatMapPayload(callable $producer, ?int $fuelTypeId, ?int $regionId): array
    {
        return Cache::remember(
            'prices:heatmap:'.($fuelTypeId ?? 'all').':'.($regionId ?? 'all'),
            now()->addMinutes(15),
            $producer,
        );
    }

    /**
     * Decide whether an incoming quote should replace the stored one.
     *
     * Authoritative sources always win. Between two crowd-grade sources the
     * newer, more confident reading wins; a stale reading never overwrites a
     * fresher one regardless of confidence.
     */
    private function supersedes(StationPrice $existing, string $source, float $confidence, \DateTimeInterface $effectiveAt): bool
    {
        if ($effectiveAt < $existing->effective_at) {
            return false;
        }

        $rank = ['ai_estimate' => 0, 'crowd' => 1, 'ocr' => 2, 'import' => 3, 'doe' => 4, 'operator' => 5];

        $incomingRank = $rank[$source] ?? 0;
        $existingRank = $rank[$existing->source] ?? 0;

        if ($incomingRank > $existingRank) {
            return true;
        }

        if ($incomingRank < $existingRank) {
            // Allow a lower-ranked source through only once the stored price
            // has gone stale — better a fresh crowd price than a week-old feed.
            return $existing->isStale();
        }

        return $confidence >= $existing->confidence;
    }

    private function assertPlausible(float $price): void
    {
        if ($price <= 0 || $price >= 1000) {
            throw new DomainException(
                'Price must be between ₱0.01 and ₱999.99 per litre.',
                'implausible_price',
                422,
                ['price' => $price],
            );
        }
    }

    private function flushCaches(GasStation $station, int $fuelTypeId): void
    {
        Cache::forget("prices:comparison:{$station->city_id}");
        Cache::forget('prices:comparison:all');
        Cache::forget("station:{$station->getKey()}:prices");

        foreach ([7, 30, 90, 365] as $window) {
            Cache::forget("prices:trend:{$fuelTypeId}:{$window}");
        }

        Cache::forget('prices:heatmap:'.$fuelTypeId.':all');
        Cache::forget('prices:heatmap:all:all');
    }
}
