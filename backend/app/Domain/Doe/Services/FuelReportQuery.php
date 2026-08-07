<?php

declare(strict_types=1);

namespace App\Domain\Doe\Services;

use App\Domain\Doe\Models\FuelPrice;
use App\Domain\Doe\Models\FuelReport;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Reads over the published DOE reports.
 *
 * Two things this class is careful about.
 *
 * **Ranges are not averaged into a single price.** Every figure the DOE
 * publishes is a min-max range per brand, and the common price is a separate
 * value it states outright. Collapsing a range to its midpoint and calling it
 * "the price" would invent a number nobody published. Midpoints appear only in
 * the trend series, where a single line is the point, and are labelled as such.
 *
 * **"Latest" is anchored to the data, not the calendar.** A region whose report
 * did not appear this week should show last week's figures rather than nothing;
 * an empty response reads as an outage.
 */
class FuelReportQuery
{
    /**
     * Aggregates are read far more often than the weekly publication changes
     * them, but a five-minute window keeps a fresh import visible promptly.
     */
    private const CACHE_TTL = 300;

    /**
     * The most recent report per region, with its prices.
     *
     * @param array<string, mixed> $filters
     * @return Collection<int, FuelReport>
     */
    public function latest(array $filters = []): Collection
    {
        $query = FuelReport::query()->latestPerRegion();

        if (! empty($filters['region'])) {
            $query->forRegion((string) $filters['region']);
        }

        return $query->orderBy('region')->get();
    }

    /**
     * Prices from the most recent report covering each region.
     *
     * @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, FuelPrice>
     */
    public function latestPrices(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $reportIds = $this->latest($filters)->pluck('id');

        $query = FuelPrice::query()
            ->with('report')
            ->whereIn('report_id', $reportIds);

        $this->applyFilters($query, $filters);

        return $query
            ->orderBy('area')
            ->orderBy('product')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * The historical series.
     *
     * @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, FuelPrice>
     */
    public function history(array $filters = [], int $perPage = 100): LengthAwarePaginator
    {
        $query = FuelPrice::query()->with('report');

        $this->applyFilters($query, $filters);
        $this->applyDateFilters($query, $filters);

        return $query
            ->join('fuel_reports', 'fuel_reports.id', '=', 'fuel_prices.report_id')
            ->orderByDesc('fuel_reports.coverage_start')
            ->select('fuel_prices.*')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * Every area the reports cover, with the region that publishes it.
     *
     * This is the closest thing the source has to a directory. The DOE's price
     * monitoring PDFs carry no station-level data at all — no names, addresses
     * or coordinates — so there is nothing here to build a station list from.
     * The platform's own /api/v1/stations remains the station directory.
     *
     * @return Collection<int, array{area: string, region: string, reports: int}>
     */
    public function areas(?string $region = null): Collection
    {
        $key = 'doe:areas:'.($region ?? 'all');

        return Cache::remember($key, self::CACHE_TTL, function () use ($region): Collection {
            $rows = DB::table('fuel_prices as p')
                ->join('fuel_reports as r', 'r.id', '=', 'p.report_id')
                ->when($region, fn ($query) => $query->where('r.region', 'like', '%'.$region.'%'))
                ->groupBy('p.area', 'r.region')
                ->orderBy('r.region')
                ->orderBy('p.area')
                ->selectRaw('p.area as area, r.region as region, COUNT(DISTINCT p.report_id) as reports')
                ->get();

            return collect($rows)->map(fn (object $row): array => [
                'area' => (string) $row->area,
                'region' => (string) $row->region,
                'reports' => (int) $row->reports,
            ])->values();
        });
    }

    /**
     * The brands the DOE monitors, with how widely they appear.
     *
     * @return Collection<int, array{brand: string, areas: int, products: int}>
     */
    public function brands(?string $region = null): Collection
    {
        $key = 'doe:brands:'.($region ?? 'all');

        return Cache::remember($key, self::CACHE_TTL, function () use ($region): Collection {
            $rows = DB::table('fuel_prices as p')
                ->join('fuel_reports as r', 'r.id', '=', 'p.report_id')
                // Excludes the per-area summary rows, which carry no brand.
                ->whereNotNull('p.brand')
                ->when($region, fn ($query) => $query->where('r.region', 'like', '%'.$region.'%'))
                ->groupBy('p.brand')
                ->orderBy('p.brand')
                ->selectRaw(
                    'p.brand as brand, COUNT(DISTINCT p.area) as areas, '
                    .'COUNT(DISTINCT p.product) as products',
                )
                ->get();

            return collect($rows)->map(fn (object $row): array => [
                'brand' => (string) $row->brand,
                'areas' => (int) $row->areas,
                'products' => (int) $row->products,
            ])->values();
        });
    }

    /**
     * Search across area, brand, product and price range.
     *
     * @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, FuelPrice>
     */
    public function search(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $query = FuelPrice::query()->with('report');

        $this->applyFilters($query, $filters);

        if (! empty($filters['date_from']) || ! empty($filters['date_to'])) {
            $this->applyDateFilters($query, $filters);
        } else {
            // Without a date bound this would return every week ever
            // published, which for a national archive is hundreds of
            // thousands of rows differing only by report.
            $query->whereIn('report_id', $this->latest($filters)->pluck('id'));
        }

        $direction = strtolower((string) ($filters['sort'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        return $query
            ->orderBy('min_price', $direction)
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * A weekly series for one grade.
     *
     * The midpoint of each published range is used, which is the one place in
     * this class a range is collapsed — a trend line needs one value per week.
     * The minimum and maximum travel with it so a caller can band the chart
     * rather than implying a precision the source does not have.
     *
     * @param array<string, mixed> $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function trends(string $fuelCode, array $filters = [], int $weeks = 26): Collection
    {
        $query = DB::table('fuel_prices as p')
            ->join('fuel_reports as r', 'r.id', '=', 'p.report_id')
            ->where('p.fuel_code', $fuelCode)
            // The per-area summary rows only. Averaging brand rows instead
            // would weight a city by how many companies it happens to list.
            ->whereNull('p.brand')
            ->whereNotNull('p.min_price')
            ->when(
                ! empty($filters['region']),
                fn ($builder) => $builder->where('r.region', 'like', '%'.$filters['region'].'%'),
            )
            ->when(
                ! empty($filters['area']),
                fn ($builder) => $builder->where('p.area', 'like', '%'.$filters['area'].'%'),
            )
            ->groupBy('r.coverage_start', 'r.coverage_end')
            ->orderByDesc('r.coverage_start')
            ->limit(max(1, $weeks))
            ->selectRaw(
                'r.coverage_start as coverage_start, r.coverage_end as coverage_end, '
                .'COUNT(DISTINCT p.area) as areas, '
                .'MIN(p.min_price) as lowest, MAX(p.max_price) as highest, '
                .'AVG((p.min_price + p.max_price) / 2) as midpoint, '
                .'AVG(p.common_price) as common',
            );

        return collect($query->get())
            ->map($this->trendPoint(...))
            // Oldest first: a chart reads left to right, and the query had to
            // sort the other way to take the most recent N.
            ->reverse()
            ->values();
    }

    /**
     * One point on a trend line.
     *
     * A named method rather than an inline array literal: Collection's value
     * type is not covariant, so a shaped array returned from a closure does not
     * satisfy a declared array<string, mixed>.
     *
     * @return array<string, mixed>
     */
    private function trendPoint(object $row): array
    {
        return [
            // Normalised rather than passed through: this is a raw query, so
            // MySQL hands back "2026-07-21" and SQLite "2026-07-21 00:00:00".
            // Letting that through would make the API's date format depend on
            // which engine happens to be behind it.
            'coverage_start' => Carbon::parse($row->coverage_start)->toDateString(),
            'coverage_end' => Carbon::parse($row->coverage_end)->toDateString(),
            'areas' => (int) $row->areas,
            'lowest' => round((float) $row->lowest, 2),
            'highest' => round((float) $row->highest, 2),
            'midpoint' => round((float) $row->midpoint, 2),
            'common' => $row->common !== null ? round((float) $row->common, 2) : null,
        ];
    }

    /**
     * @param Builder<FuelPrice> $query
     * @param array<string, mixed> $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        foreach (['area' => 'area', 'brand' => 'brand', 'product' => 'product'] as $key => $column) {
            if (! empty($filters[$key])) {
                $query->where($column, 'like', '%'.$filters[$key].'%');
            }
        }

        if (! empty($filters['fuel_code'])) {
            $query->where('fuel_code', $filters['fuel_code']);
        }

        if (! empty($filters['region'])) {
            $query->whereHas(
                'report',
                fn (Builder $report) => $report->where('region', 'like', '%'.$filters['region'].'%'),
            );
        }

        if (isset($filters['min_price']) && $filters['min_price'] !== '') {
            $query->where('max_price', '>=', (float) $filters['min_price']);
        }

        if (isset($filters['max_price']) && $filters['max_price'] !== '') {
            $query->where('min_price', '<=', (float) $filters['max_price']);
        }

        if (filter_var($filters['branded_only'] ?? false, FILTER_VALIDATE_BOOL)) {
            $query->branded();
        }
    }

    /**
     * @param Builder<FuelPrice> $query
     * @param array<string, mixed> $filters
     */
    private function applyDateFilters(Builder $query, array $filters): void
    {
        if (empty($filters['date_from']) && empty($filters['date_to'])) {
            return;
        }

        $query->whereHas('report', function (Builder $report) use ($filters): void {
            if (! empty($filters['date_from'])) {
                $report->whereDate('coverage_end', '>=', $filters['date_from']);
            }

            if (! empty($filters['date_to'])) {
                $report->whereDate('coverage_start', '<=', $filters['date_to']);
            }
        });
    }
}
