<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Repositories;

use App\Domain\Pricing\Models\FuelPriceHistory;
use App\Domain\Pricing\Models\PriceAdvisory;
use App\Domain\Pricing\Models\StationPrice;
use App\Support\Repositories\BaseRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/** @extends BaseRepository<StationPrice> */
class PriceRepository extends BaseRepository
{
    protected array $filterable = ['station_id', 'fuel_type_id', 'source'];

    protected array $sortable = ['id', 'price', 'effective_at', 'updated_at'];

    protected function model(): string
    {
        return StationPrice::class;
    }

    protected function dateColumn(): string
    {
        return 'effective_at';
    }

    public function currentFor(int $stationId, int $fuelTypeId): ?StationPrice
    {
        return $this->query()
            ->where('station_id', $stationId)
            ->where('fuel_type_id', $fuelTypeId)
            ->first();
    }

    /**
     * Median live price for a fuel type in a city. Used to reject wildly
     * out-of-band crowd submissions; the median resists a handful of bad rows
     * far better than the mean.
     */
    public function cityMedian(int $cityId, int $fuelTypeId): ?float
    {
        $prices = DB::table('station_prices as sp')
            ->join('gas_stations as gs', 'gs.id', '=', 'sp.station_id')
            ->where('gs.city_id', $cityId)
            ->where('sp.fuel_type_id', $fuelTypeId)
            ->whereNull('gs.deleted_at')
            ->orderBy('sp.price')
            ->pluck('sp.price')
            ->map(static fn ($p) => (float) $p)
            ->all();

        $count = count($prices);

        if ($count === 0) {
            return null;
        }

        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? $prices[$middle]
            : round(($prices[$middle - 1] + $prices[$middle]) / 2, 4);
    }

    /** Daily national average series for the trend chart. */
    public function nationalTrend(int $fuelTypeId, int $days = 90): array
    {
        return DB::connection()
            ->table('fuel_price_history')
            ->where('fuel_type_id', $fuelTypeId)
            ->where('recorded_on', '>=', now()->subDays($days)->toDateString())
            ->selectRaw('recorded_on, ROUND(AVG(price), 4) AS avg_price, MIN(price) AS min_price, MAX(price) AS max_price, COUNT(*) AS samples')
            ->groupBy('recorded_on')
            ->orderBy('recorded_on')
            ->get()
            ->map(static fn ($row) => [
                'date' => $row->recorded_on,
                'avg_price' => (float) $row->avg_price,
                'min_price' => (float) $row->min_price,
                'max_price' => (float) $row->max_price,
                'samples' => (int) $row->samples,
            ])->all();
    }

    /** Per-station history, for the station detail chart. */
    public function stationHistory(int $stationId, int $fuelTypeId, int $days = 90): array
    {
        return FuelPriceHistory::query()
            ->where('station_id', $stationId)
            ->where('fuel_type_id', $fuelTypeId)
            ->where('recorded_on', '>=', now()->subDays($days)->toDateString())
            ->orderBy('recorded_at')
            ->get(['recorded_at', 'price', 'source'])
            ->map(static fn (FuelPriceHistory $row) => [
                'recorded_at' => $row->recorded_at->toIso8601String(),
                'price' => (float) $row->price,
                'source' => $row->source,
            ])->all();
    }

    /** The last N weekly DOE adjustments for a fuel type. */
    public function advisoryHistory(int $fuelTypeId, int $weeks = 12, ?int $regionId = null): array
    {
        return PriceAdvisory::query()
            ->where('fuel_type_id', $fuelTypeId)
            ->when($regionId === null, fn ($q) => $q->whereNull('region_id'), fn ($q) => $q->where('region_id', $regionId))
            ->recent($weeks)
            ->get()
            ->map(static fn (PriceAdvisory $a) => [
                'week_start' => $a->week_start->toDateString(),
                'effective_at' => $a->effective_at->toIso8601String(),
                'direction' => $a->direction,
                'change_amount' => (float) $a->change_amount,
                'notes' => $a->notes,
            ])->all();
    }

    /** Cheapest and priciest live prices per fuel type, for comparison tables. */
    public function comparisonMatrix(?int $cityId = null): array
    {
        $query = DB::connection()
            ->table('station_prices as sp')
            ->join('gas_stations as gs', 'gs.id', '=', 'sp.station_id')
            ->join('brands as b', 'b.id', '=', 'gs.brand_id')
            ->join('fuel_types as ft', 'ft.id', '=', 'sp.fuel_type_id')
            ->whereNull('gs.deleted_at')
            ->where('gs.status', 'active');

        if ($cityId !== null) {
            $query->where('gs.city_id', $cityId);
        }

        return $query->selectRaw('
                ft.id AS fuel_type_id, ft.code AS fuel_code, ft.name AS fuel_name,
                ROUND(AVG(sp.price), 4) AS avg_price,
                MIN(sp.price) AS min_price,
                MAX(sp.price) AS max_price,
                COUNT(DISTINCT gs.id) AS station_count
            ')
            ->groupBy('ft.id', 'ft.code', 'ft.name')
            ->orderBy('ft.id')
            ->get()
            ->map(static fn ($row) => [
                'fuel_type_id' => (int) $row->fuel_type_id,
                'fuel_code' => $row->fuel_code,
                'fuel_name' => $row->fuel_name,
                'avg_price' => (float) $row->avg_price,
                'min_price' => (float) $row->min_price,
                'max_price' => (float) $row->max_price,
                'spread' => round((float) $row->max_price - (float) $row->min_price, 4),
                'station_count' => (int) $row->station_count,
            ])->all();
    }

    /** Week-on-week movement per region, for the executive dashboard. */
    public function regionalMovement(int $fuelTypeId, ?Carbon $asOf = null): array
    {
        $asOf ??= now();
        $thisWeek = $asOf->copy()->startOfWeek()->toDateString();
        $lastWeek = $asOf->copy()->subWeek()->startOfWeek()->toDateString();

        return DB::connection()
            ->table('fuel_price_history as h')
            ->join('regions as r', 'r.id', '=', 'h.region_id')
            ->where('h.fuel_type_id', $fuelTypeId)
            ->whereBetween('h.recorded_on', [$lastWeek, $asOf->toDateString()])
            ->selectRaw('
                r.id AS region_id, r.name AS region_name,
                ROUND(AVG(CASE WHEN h.recorded_on >= ? THEN h.price END), 4) AS current_avg,
                ROUND(AVG(CASE WHEN h.recorded_on <  ? THEN h.price END), 4) AS previous_avg
            ', [$thisWeek, $thisWeek])
            ->groupBy('r.id', 'r.name')
            ->get()
            ->map(static function ($row) {
                $current = $row->current_avg !== null ? (float) $row->current_avg : null;
                $previous = $row->previous_avg !== null ? (float) $row->previous_avg : null;

                return [
                    'region_id' => (int) $row->region_id,
                    'region_name' => $row->region_name,
                    'current_avg' => $current,
                    'previous_avg' => $previous,
                    'change' => ($current !== null && $previous !== null) ? round($current - $previous, 4) : null,
                    'change_pct' => ($current !== null && $previous) ? round((($current - $previous) / $previous) * 100, 2) : null,
                ];
            })->all();
    }
}
