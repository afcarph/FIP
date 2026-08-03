<?php

declare(strict_types=1);

namespace App\Domain\Station\Repositories;

use App\Domain\Station\Models\GasStation;
use App\Support\Repositories\BaseRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/** @extends BaseRepository<GasStation> */
class GasStationRepository extends BaseRepository
{
    protected array $filterable = ['brand_id', 'city_id', 'status', 'is_24_hours', 'has_ev_charging', 'operator_id'];

    protected array $sortable = ['id', 'name', 'rating_avg', 'created_at'];

    protected array $searchable = ['name', 'address_line'];

    protected function model(): string
    {
        return GasStation::class;
    }

    public function paginateDirectory(int $perPage = 15, array $filters = []): LengthAwarePaginator
    {
        $query = $this->applyFilters($this->query()->active(), $filters);

        if (! empty($filters['amenities'])) {
            foreach ((array) $filters['amenities'] as $amenityId) {
                $query->whereHas('amenities', fn (Builder $q) => $q->where('amenities.id', $amenityId));
            }
        }

        if (! empty($filters['payment_methods'])) {
            $query->whereHas('paymentMethods', fn (Builder $q) => $q->whereIn('payment_methods.id', (array) $filters['payment_methods']));
        }

        if (! empty($filters['fuel_type_id'])) {
            $query->sellingFuel((int) $filters['fuel_type_id']);
        }

        return $query
            ->with(['brand', 'city.province.region', 'prices.fuelType', 'amenities'])
            ->paginate(min($perPage, 100))
            ->withQueryString();
    }

    /**
     * Stations within `radiusKm`, each carrying `distance_m` and eager-loaded
     * live prices. Optionally restricted to those selling a given fuel type.
     *
     * @return Collection<int, GasStation>
     */
    public function nearby(float $lat, float $lng, float $radiusKm = 5.0, ?int $fuelTypeId = null, int $limit = 25): Collection
    {
        $query = $this->query()
            ->active()
            ->withinRadius($lat, $lng, $radiusKm)
            ->with(['brand', 'prices' => fn ($q) => $fuelTypeId ? $q->where('fuel_type_id', $fuelTypeId) : $q, 'prices.fuelType']);

        if ($fuelTypeId !== null) {
            $query->sellingFuel($fuelTypeId);
        }

        return $query->limit($limit)->get();
    }

    /**
     * The cheapest stations for one fuel type within a radius, ranked by price
     * then distance. Powers the "cheapest near me" card and the AI advisor.
     *
     * @return Collection<int, GasStation>
     */
    public function cheapestNearby(float $lat, float $lng, int $fuelTypeId, float $radiusKm = 5.0, int $limit = 10): Collection
    {
        return $this->query()
            ->active()
            ->withinRadius($lat, $lng, $radiusKm)
            ->join('station_prices', function ($join) use ($fuelTypeId): void {
                $join->on('station_prices.station_id', '=', 'gas_stations.id')
                    ->where('station_prices.fuel_type_id', '=', $fuelTypeId);
            })
            ->addSelect(['station_prices.price AS current_price', 'station_prices.effective_at AS price_effective_at'])
            ->with('brand')
            ->reorder()
            ->orderBy('station_prices.price')
            ->orderBy('distance_m')
            ->limit($limit)
            ->get();
    }

    /**
     * Aggregated price grid for the heat map: one row per city per fuel type.
     * Runs on the read replica because it scans the whole live price table.
     */
    public function heatMap(?int $fuelTypeId = null, ?int $regionId = null): array
    {
        $query = DB::connection()
            ->table('station_prices as sp')
            ->join('gas_stations as gs', 'gs.id', '=', 'sp.station_id')
            ->join('cities as c', 'c.id', '=', 'gs.city_id')
            ->join('provinces as p', 'p.id', '=', 'c.province_id')
            ->join('regions as r', 'r.id', '=', 'p.region_id')
            ->whereNull('gs.deleted_at')
            ->where('gs.status', 'active')
            ->selectRaw('
                c.id AS city_id, c.name AS city_name, c.latitude, c.longitude,
                r.id AS region_id, r.name AS region_name,
                sp.fuel_type_id,
                ROUND(AVG(sp.price), 2) AS avg_price,
                MIN(sp.price) AS min_price,
                MAX(sp.price) AS max_price,
                COUNT(*) AS station_count
            ')
            ->groupBy('c.id', 'c.name', 'c.latitude', 'c.longitude', 'r.id', 'r.name', 'sp.fuel_type_id');

        if ($fuelTypeId !== null) {
            $query->where('sp.fuel_type_id', $fuelTypeId);
        }

        if ($regionId !== null) {
            $query->where('r.id', $regionId);
        }

        return $query->get()->map(static fn ($row) => [
            'city_id' => (int) $row->city_id,
            'city_name' => $row->city_name,
            'region_id' => (int) $row->region_id,
            'region_name' => $row->region_name,
            'latitude' => (float) $row->latitude,
            'longitude' => (float) $row->longitude,
            'fuel_type_id' => (int) $row->fuel_type_id,
            'avg_price' => (float) $row->avg_price,
            'min_price' => (float) $row->min_price,
            'max_price' => (float) $row->max_price,
            'station_count' => (int) $row->station_count,
        ])->all();
    }

    public function findBySlug(string $slug): ?GasStation
    {
        return $this->query()
            ->where('slug', $slug)
            ->with(['brand', 'city.province.region', 'prices.fuelType', 'amenities', 'paymentMethods', 'hours', 'photos'])
            ->first();
    }

    /** Stations managed by a station administrator. */
    public function managedBy(int $userId): Collection
    {
        return $this->query()->where('managed_by', $userId)->with(['brand', 'prices.fuelType'])->get();
    }
}
