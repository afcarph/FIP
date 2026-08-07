<?php

declare(strict_types=1);

namespace App\Domain\Doe\Services;

use App\Domain\Doe\Models\DoePrice;
use App\Domain\Doe\Models\DoeStation;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Reads over the scraped DOE data: search, rankings, trends and comparisons.
 *
 * Two things this class is careful about throughout.
 *
 * **Fuel columns are interpolated into SQL.** They have to be — the grain is
 * one row with six price columns, so "cheapest RON 95" is a different column
 * rather than a different value. Every entry point therefore runs the fuel
 * through {@see assertFuel} first, which checks it against DoePrice::FUELS.
 * Nothing else in this class may interpolate a caller-supplied string.
 *
 * **Averages must exclude missing grades, not treat them as zero.** Almost no
 * station sells all six, so `AVG(ron97)` over a region where a tenth of
 * stations carry it is only meaningful because SQL's AVG skips NULLs. A
 * COALESCE to 0 anywhere here would drag every regional average towards zero
 * and make the cheapest-station ranking meaningless.
 */
class FuelQueryService
{
    /**
     * Rankings and national statistics are read far more often than the daily
     * scrape writes them, and they aggregate the whole table. Five minutes is
     * short enough that the morning's run shows up promptly.
     */
    private const CACHE_TTL = 300;

    // -- guards --------------------------------------------------------------

    /**
     * Validate a fuel column against the allow-list.
     *
     * The one place a caller-supplied string becomes part of a query. Anything
     * not on the list throws rather than being silently ignored: a typo in
     * `?fuel=` should be an error, not a response computed over a column the
     * caller did not ask for.
     */
    public function assertFuel(string $fuel): string
    {
        $normalized = strtolower(trim($fuel));

        if (! in_array($normalized, DoePrice::FUELS, true)) {
            throw new InvalidArgumentException(sprintf(
                'Unknown fuel type "%s". Expected one of: %s.',
                $fuel,
                implode(', ', DoePrice::FUELS),
            ));
        }

        return $normalized;
    }

    /**
     * @param array<int, string>|null $fuels
     * @return list<string>
     */
    public function assertFuels(?array $fuels): array
    {
        if ($fuels === null || $fuels === []) {
            return DoePrice::FUELS;
        }

        return array_values(array_map($this->assertFuel(...), $fuels));
    }

    // -- basics --------------------------------------------------------------

    /**
     * The most recent date the scraper recorded anything for.
     *
     * Not today's date: a run can fail, and a public holiday means the DOE
     * publishes nothing. Anchoring "latest" to the calendar rather than to the
     * data returns an empty result and reads as an outage.
     */
    public function latestDate(): ?Carbon
    {
        $value = DoePrice::query()->max('price_date');

        return $value ? Carbon::parse($value) : null;
    }

    /**
     * Latest prices, one row per station, newest date first.
     *
     * @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, DoePrice>
     */
    public function latest(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $date = $this->latestDate();

        $query = DoePrice::query()->with('station');

        if ($date !== null) {
            $query->whereDate('price_date', $date);
        }

        $this->applyStationFilters($query, $filters);
        $this->applyPriceFilters($query, $filters);

        return $query
            ->orderByDesc('price_date')
            ->orderBy('station_id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * The price series, optionally for one station and date window.
     *
     * @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, DoePrice>
     */
    public function history(array $filters = [], int $perPage = 50): LengthAwarePaginator
    {
        $query = DoePrice::query()->with('station');

        if (! empty($filters['station_id'])) {
            $query->where('station_id', (int) $filters['station_id']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('price_date', '>=', Carbon::parse($filters['date_from']));
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('price_date', '<=', Carbon::parse($filters['date_to']));
        }

        $this->applyStationFilters($query, $filters);
        $this->applyPriceFilters($query, $filters);

        return $query
            ->orderByDesc('price_date')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * The station directory.
     *
     * @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, DoeStation>
     */
    public function stations(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = DoeStation::query();

        foreach (['company', 'province', 'city', 'barangay', 'region'] as $column) {
            if (! empty($filters[$column])) {
                $query->where($column, 'like', '%'.$filters[$column].'%');
            }
        }

        if (! empty($filters['q'])) {
            $term = '%'.$filters['q'].'%';
            $query->where(function (Builder $inner) use ($term): void {
                $inner->where('name', 'like', $term)
                    ->orWhere('company', 'like', $term)
                    ->orWhere('address', 'like', $term)
                    ->orWhere('city', 'like', $term)
                    ->orWhere('barangay', 'like', $term);
            });
        }

        if (filter_var($filters['with_coordinates'] ?? false, FILTER_VALIDATE_BOOL)) {
            $query->whereNotNull('latitude')->whereNotNull('longitude');
        }

        return $query
            ->orderBy('company')
            ->orderBy('city')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * The full search: geography, company, station, fuel, price range and date.
     *
     * @param array<string, mixed> $filters
     * @return LengthAwarePaginator<int, DoePrice>
     */
    public function search(array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = DoePrice::query()->with('station');

        $this->applyStationFilters($query, $filters);
        $this->applyPriceFilters($query, $filters);

        if (! empty($filters['date'])) {
            $query->whereDate('price_date', Carbon::parse($filters['date']));
        } elseif (empty($filters['date_from']) && empty($filters['date_to'])) {
            // Without a date the search would return every day the scraper has
            // ever recorded — for a national feed, millions of rows whose only
            // difference is the date. Default to the latest.
            $date = $this->latestDate();
            if ($date !== null) {
                $query->whereDate('price_date', $date);
            }
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('price_date', '>=', Carbon::parse($filters['date_from']));
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('price_date', '<=', Carbon::parse($filters['date_to']));
        }

        // Sorting by price only makes sense for one grade — "cheapest" across
        // six columns is not defined.
        if (! empty($filters['fuel'])) {
            $fuel = $this->assertFuel((string) $filters['fuel']);
            $direction = strtolower((string) ($filters['sort'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

            $query->whereNotNull($fuel)->orderBy($fuel, $direction);
        } else {
            $query->orderByDesc('price_date');
        }

        return $query->paginate($perPage)->withQueryString();
    }

    // -- filters -------------------------------------------------------------

    /**
     * Geography and company, applied to a price query through its station.
     *
     * @param Builder<DoePrice> $query
     * @param array<string, mixed> $filters
     */
    private function applyStationFilters(Builder $query, array $filters): void
    {
        $stationFilters = array_filter([
            'company' => $filters['company'] ?? null,
            'region' => $filters['region'] ?? null,
            'province' => $filters['province'] ?? null,
            'city' => $filters['city'] ?? $filters['municipality'] ?? null,
            'barangay' => $filters['barangay'] ?? null,
        ]);

        $stationTerm = $filters['station'] ?? $filters['q'] ?? null;

        if ($stationFilters === [] && ! $stationTerm) {
            return;
        }

        $query->whereHas('station', function (Builder $station) use ($stationFilters, $stationTerm): void {
            foreach ($stationFilters as $column => $value) {
                $station->where($column, 'like', '%'.$value.'%');
            }

            if ($stationTerm) {
                $term = '%'.$stationTerm.'%';
                $station->where(function (Builder $inner) use ($term): void {
                    $inner->where('name', 'like', $term)
                        ->orWhere('company', 'like', $term)
                        ->orWhere('address', 'like', $term);
                });
            }
        });
    }

    /**
     * Price range, scoped to one grade.
     *
     * A range without a fuel type is ambiguous — a station is "under ₱60" for
     * diesel and over it for RON 97 — so it is only applied when a fuel is
     * given.
     *
     * @param Builder<DoePrice> $query
     * @param array<string, mixed> $filters
     */
    private function applyPriceFilters(Builder $query, array $filters): void
    {
        if (empty($filters['fuel'])) {
            return;
        }

        $fuel = $this->assertFuel((string) $filters['fuel']);

        if (isset($filters['min_price']) && $filters['min_price'] !== '') {
            $query->where($fuel, '>=', (float) $filters['min_price']);
        }

        if (isset($filters['max_price']) && $filters['max_price'] !== '') {
            $query->where($fuel, '<=', (float) $filters['max_price']);
        }
    }

    // -- statistics ----------------------------------------------------------

    /**
     * Lowest, highest and average for one grade on one date.
     *
     * @return array{fuel: string, label: string, date: string|null, stations: int, lowest: float|null, highest: float|null, average: float|null, spread: float|null}
     */
    public function statistics(string $fuel, ?Carbon $date = null, array $filters = []): array
    {
        $fuel = $this->assertFuel($fuel);
        $date ??= $this->latestDate();

        $query = DoePrice::query()->whereNotNull($fuel);

        if ($date !== null) {
            $query->whereDate('price_date', $date);
        }

        $this->applyStationFilters($query, $filters);

        /** @var object{stations: int, lowest: float|null, highest: float|null, average: float|null}|null $row */
        $row = $query->selectRaw(sprintf(
            'COUNT(*) as stations, MIN(`%1$s`) as lowest, MAX(`%1$s`) as highest, AVG(`%1$s`) as average',
            $fuel,
        ))->first();

        // An aggregate over no rows still returns a row, with every column
        // null — so the guard is on the values, not on the row.
        $lowest = $row !== null && $row->lowest !== null ? (float) $row->lowest : null;
        $highest = $row !== null && $row->highest !== null ? (float) $row->highest : null;
        $average = $row !== null && $row->average !== null ? (float) $row->average : null;

        return [
            'fuel' => $fuel,
            'label' => DoePrice::FUEL_LABELS[$fuel],
            'date' => $date?->toDateString(),
            'stations' => $row !== null ? (int) $row->stations : 0,
            'lowest' => $lowest !== null ? round($lowest, 2) : null,
            'highest' => $highest !== null ? round($highest, 2) : null,
            'average' => $average !== null ? round($average, 2) : null,
            // What a driver actually saves by choosing well. It is the number
            // the whole product is for, so it is computed rather than left to
            // the client to subtract.
            'spread' => ($lowest !== null && $highest !== null) ? round($highest - $lowest, 2) : null,
        ];
    }

    /**
     * The cheapest and most expensive stations for one grade.
     *
     * @return array{cheapest: DoePrice|null, most_expensive: DoePrice|null}
     */
    public function extremes(string $fuel, ?Carbon $date = null, array $filters = []): array
    {
        $fuel = $this->assertFuel($fuel);
        $date ??= $this->latestDate();

        $base = fn (): Builder => tap(
            DoePrice::query()->with('station')->whereNotNull($fuel),
            function (Builder $query) use ($date, $filters): void {
                if ($date !== null) {
                    $query->whereDate('price_date', $date);
                }
                $this->applyStationFilters($query, $filters);
            },
        );

        return [
            'cheapest' => $base()->orderBy($fuel)->first(),
            'most_expensive' => $base()->orderByDesc($fuel)->first(),
        ];
    }

    /**
     * Average price per province or per city, cheapest first.
     *
     * `$level` is typed as a plain string on purpose. It becomes a column name
     * in the GROUP BY below, so the runtime check is the control that makes
     * that safe — the same role assertFuel plays for the price columns. A
     * narrower PHPDoc would make the check look redundant to a reader and to
     * static analysis, while doing nothing to stop a caller passing a string
     * from a request.
     *
     * @return Collection<int, array{name: string, stations: int, average: float, lowest: float, highest: float}>
     */
    public function ranking(string $level, string $fuel, ?Carbon $date = null, int $limit = 20): Collection
    {
        if (! in_array($level, ['province', 'city', 'region'], true)) {
            throw new InvalidArgumentException("Ranking level must be province, city or region, got \"{$level}\".");
        }

        $fuel = $this->assertFuel($fuel);
        $date ??= $this->latestDate();

        $key = sprintf('doe:ranking:%s:%s:%s:%d', $level, $fuel, $date?->toDateString() ?? 'none', $limit);

        return Cache::remember($key, self::CACHE_TTL, function () use ($level, $fuel, $date, $limit): Collection {
            $query = DB::connection('doe')
                ->table('fuel_price_history as p')
                ->join('fuel_stations as s', 's.id', '=', 'p.station_id')
                ->whereNotNull("p.{$fuel}")
                // A row with no geography cannot be ranked, and grouping it in
                // would produce an unlabelled bucket at an arbitrary position.
                ->whereNotNull("s.{$level}")
                ->where("s.{$level}", '!=', '')
                ->groupBy("s.{$level}")
                ->orderBy('average')
                ->limit($limit)
                ->selectRaw(sprintf(
                    's.%1$s as name, COUNT(*) as stations, AVG(p.`%2$s`) as average, '
                    .'MIN(p.`%2$s`) as lowest, MAX(p.`%2$s`) as highest',
                    $level,
                    $fuel,
                ));

            if ($date !== null) {
                $query->whereDate('p.price_date', $date);
            }

            return collect($query->get())->map(fn (object $row): array => [
                'name' => (string) $row->name,
                'stations' => (int) $row->stations,
                'average' => round((float) $row->average, 2),
                'lowest' => round((float) $row->lowest, 2),
                'highest' => round((float) $row->highest, 2),
            ])->values();
        });
    }

    /**
     * The national daily average series for one grade.
     *
     * @return Collection<int, array{date: string, average: float, lowest: float, highest: float, stations: int}>
     */
    public function trend(string $fuel, int $days = 30, array $filters = []): Collection
    {
        $fuel = $this->assertFuel($fuel);
        $since = Carbon::today()->subDays(max(1, $days));

        $query = DoePrice::query()
            ->whereNotNull($fuel)
            ->whereDate('price_date', '>=', $since);

        $this->applyStationFilters($query, $filters);

        $rows = $query
            ->groupBy('price_date')
            ->orderBy('price_date')
            ->selectRaw(sprintf(
                'price_date, COUNT(*) as stations, AVG(`%1$s`) as average, '
                .'MIN(`%1$s`) as lowest, MAX(`%1$s`) as highest',
                $fuel,
            ))
            ->get();

        return $rows->map(fn (DoePrice $row): array => [
            'date' => Carbon::parse($row->price_date)->toDateString(),
            'average' => round((float) $row->getAttribute('average'), 2),
            'lowest' => round((float) $row->getAttribute('lowest'), 2),
            'highest' => round((float) $row->getAttribute('highest'), 2),
            'stations' => (int) $row->getAttribute('stations'),
        ])->values();
    }

    /**
     * Change over a window, as an amount and a percentage.
     *
     * Compared against the nearest recorded date at or before the target, not
     * the target itself. The DOE does not publish every day, so asking for
     * "exactly seven days ago" returns nothing roughly as often as it returns
     * a number, and a null change reads as "prices did not move".
     *
     * @return array{fuel: string, label: string, from: array{date: string, average: float}|null, to: array{date: string, average: float}|null, change: float|null, percent: float|null, direction: string}
     */
    public function change(string $fuel, int $days, array $filters = []): array
    {
        $fuel = $this->assertFuel($fuel);

        $latest = $this->latestDate();

        if ($latest === null) {
            return $this->emptyChange($fuel);
        }

        $earlierDate = $this->nearestDateOnOrBefore($latest->copy()->subDays($days));

        if ($earlierDate === null) {
            return $this->emptyChange($fuel);
        }

        $current = $this->averageOn($fuel, $latest, $filters);
        $previous = $this->averageOn($fuel, $earlierDate, $filters);

        if ($current === null || $previous === null) {
            return $this->emptyChange($fuel);
        }

        $change = round($current - $previous, 2);

        return [
            'fuel' => $fuel,
            'label' => DoePrice::FUEL_LABELS[$fuel],
            'from' => ['date' => $earlierDate->toDateString(), 'average' => $previous],
            'to' => ['date' => $latest->toDateString(), 'average' => $current],
            'change' => $change,
            // Guarded: a previous average of zero would be a data error, but
            // dividing by it would turn that into a 500.
            'percent' => $previous > 0 ? round(($change / $previous) * 100, 2) : null,
            // The DOE's own vocabulary, and the same words the platform's
            // advisories use, so the two read consistently.
            'direction' => match (true) {
                $change > 0 => 'increase',
                $change < 0 => 'rollback',
                default => 'no_change',
            },
        ];
    }

    /**
     * Weekly and monthly movement for every grade.
     *
     * @return array<string, array{weekly: array<string, mixed>, monthly: array<string, mixed>}>
     */
    public function changes(array $filters = []): array
    {
        $changes = [];

        foreach (DoePrice::FUELS as $fuel) {
            $changes[$fuel] = [
                'weekly' => $this->change($fuel, 7, $filters),
                'monthly' => $this->change($fuel, 30, $filters),
            ];
        }

        return $changes;
    }

    /**
     * Compare named stations, or the cheapest stations in an area, side by side.
     *
     * @param list<int> $stationIds
     * @return Collection<int, array<string, mixed>>
     */
    public function compare(array $stationIds, ?Carbon $date = null): Collection
    {
        $date ??= $this->latestDate();

        if ($stationIds === [] || $date === null) {
            return collect();
        }

        $prices = DoePrice::query()
            ->with('station')
            ->whereIn('station_id', $stationIds)
            ->whereDate('price_date', $date)
            ->get();

        // Cheapest per grade across the compared set, so the response can mark
        // a winner rather than making the client compute it.
        $best = [];
        foreach (DoePrice::FUELS as $fuel) {
            $values = $prices->pluck($fuel)->filter(fn ($value): bool => $value !== null);
            $best[$fuel] = $values->isNotEmpty() ? (float) $values->min() : null;
        }

        return $prices
            ->map(fn (DoePrice $price): array => $this->comparisonRow($price, $best))
            ->values();
    }

    /**
     * One station's entry in a comparison.
     *
     * @param array<string, float|null> $best
     * @return array<string, mixed>
     */
    private function comparisonRow(DoePrice $price, array $best): array
    {
        $fuels = [];

        foreach (DoePrice::FUELS as $fuel) {
            $value = $price->{$fuel};
            $cheapest = $best[$fuel] ?? null;

            $fuels[$fuel] = [
                'price' => $value !== null ? round((float) $value, 2) : null,
                'label' => DoePrice::FUEL_LABELS[$fuel],
                // Compared with a tolerance rather than for equality: these are
                // decimals from the database against a float minimum, and an
                // exact comparison marks no winner about as often as it works.
                'is_cheapest' => $value !== null && $cheapest !== null
                    && abs((float) $value - $cheapest) < 0.005,
            ];
        }

        return [
            'station' => [
                'id' => $price->station?->id,
                'name' => $price->station?->label,
                'company' => $price->station?->company,
                'city' => $price->station?->city,
                'province' => $price->station?->province,
                'address' => $price->station?->display_address,
                'latitude' => $price->station?->latitude,
                'longitude' => $price->station?->longitude,
            ],
            'price_date' => $price->price_date->toDateString(),
            'fuels' => $fuels,
        ];
    }

    // -- internals -----------------------------------------------------------

    private function averageOn(string $fuel, Carbon $date, array $filters = []): ?float
    {
        $query = DoePrice::query()->whereNotNull($fuel)->whereDate('price_date', $date);
        $this->applyStationFilters($query, $filters);

        $average = $query->avg($fuel);

        return $average !== null ? round((float) $average, 2) : null;
    }

    private function nearestDateOnOrBefore(Carbon $target): ?Carbon
    {
        $value = DoePrice::query()
            ->whereDate('price_date', '<=', $target)
            ->max('price_date');

        return $value ? Carbon::parse($value) : null;
    }

    /**
     * @return array{fuel: string, label: string, from: null, to: null, change: null, percent: null, direction: string}
     */
    private function emptyChange(string $fuel): array
    {
        return [
            'fuel' => $fuel,
            'label' => DoePrice::FUEL_LABELS[$fuel],
            'from' => null,
            'to' => null,
            'change' => null,
            'percent' => null,
            // Not "no_change": there is a difference between prices holding
            // steady and having nothing to compare, and a client that shows a
            // flat arrow for both is lying about the second.
            'direction' => 'unknown',
        ];
    }
}
