<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Doe\Models\DoePrice;
use App\Domain\Doe\Models\DoeStation;
use App\Domain\Pricing\Models\FuelType;
use App\Domain\Pricing\Services\PriceService;
use App\Domain\Station\Models\Brand;
use App\Domain\Station\Models\City;
use App\Domain\Station\Models\GasStation;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Move scraped DOE prices into the platform's own price model.
 *
 * The scraper writes to its own database in the DOE's grain — one row per
 * station per date, all six grades as columns. The platform stores one row per
 * station per fuel type, with the series in fuel_price_history, and everything
 * downstream (the map, the trend chart, the forecast, the advisor) reads that.
 * This command is the only path between the two.
 *
 * It goes through PriceService::recordPrice rather than inserting rows, for the
 * same reason StationPriceSeeder does: station_prices holds only the current
 * price, and recordPrice is what also appends to fuel_price_history. Writing
 * the table directly would give a populated map and a forecast with nothing to
 * read.
 *
 *   php artisan fip:sync-doe-prices
 *   php artisan fip:sync-doe-prices --date=2026-08-06
 *   php artisan fip:sync-doe-prices --dry-run
 *   php artisan fip:sync-doe-prices --create-missing
 */
class SyncDoeScrapedPrices extends Command
{
    protected $signature = 'fip:sync-doe-prices
        {--date= : The scraped date to sync (default: the most recent)}
        {--dry-run : Report what would be written without writing it}
        {--create-missing : Create a platform station for a DOE station with no match}
        {--limit=0 : Stop after this many price rows (0 for all)}';

    protected $description = 'Sync scraped DOE prices into the platform price model';

    /**
     * DOE grade to the platform's fuel type code.
     *
     * ron100 is deliberately absent. The platform has no RON 100 fuel type,
     * and mapping it onto RON 97 would file one product's price under another
     * — which reads as a plausible price and is undetectable downstream. Rows
     * carrying it are reported as skipped instead.
     *
     * @var array<string, string>
     */
    private const FUEL_MAP = [
        'ron91' => 'gasoline_ron91',
        'ron95' => 'gasoline_ron95',
        'ron97' => 'gasoline_ron97',
        'diesel' => 'diesel',
        'diesel_plus' => 'diesel_premium',
    ];

    public function handle(PriceService $prices): int
    {
        if (! $this->doeDatabaseReachable()) {
            return self::FAILURE;
        }

        $date = $this->resolveDate();

        if ($date === null) {
            $this->error('The scraper database holds no prices. Run the scraper first.');

            return self::FAILURE;
        }

        $this->info("Syncing DOE prices for {$date->toDateString()}.");

        $fuelTypes = FuelType::whereIn('code', array_values(self::FUEL_MAP))
            ->get()
            ->keyBy('code');

        if ($fuelTypes->isEmpty()) {
            $this->error('No matching fuel types. Run ReferenceDataSeeder first.');

            return self::FAILURE;
        }

        $query = DoePrice::query()
            ->with('station')
            ->whereDate('price_date', $date);

        if (($limit = (int) $this->option('limit')) > 0) {
            $query->limit($limit);
        }

        $counts = [
            'rows' => 0,
            'written' => 0,
            'matched' => 0,
            'created' => 0,
            'unmatched' => 0,
            'unmappable' => 0,
        ];
        $unmatched = [];

        // Chunked: a national feed is thousands of stations, and loading them
        // all before writing anything would hold the whole table in memory for
        // no benefit.
        $query->chunkById(500, function (Collection $chunk) use (
            $prices, $fuelTypes, $date, &$counts, &$unmatched
        ): void {
            foreach ($chunk as $price) {
                $counts['rows']++;

                $doeStation = $price->station;

                if ($doeStation === null) {
                    $counts['unmatched']++;

                    continue;
                }

                $station = $this->matchStation($doeStation, $counts);

                if ($station === null) {
                    $counts['unmatched']++;
                    $unmatched[] = trim("{$doeStation->company} {$doeStation->name} — {$doeStation->city}");

                    continue;
                }

                foreach ($price->pricedFuels() as $grade => $amount) {
                    if (! isset(self::FUEL_MAP[$grade])) {
                        $counts['unmappable']++;

                        continue;
                    }

                    $fuelType = $fuelTypes->get(self::FUEL_MAP[$grade]);

                    if ($fuelType === null) {
                        $counts['unmappable']++;

                        continue;
                    }

                    if ($this->option('dry-run')) {
                        $counts['written']++;

                        continue;
                    }

                    try {
                        $prices->recordPrice(
                            station: $station,
                            fuelTypeId: $fuelType->getKey(),
                            price: $amount,
                            // Distinguishable from an operator's own entry and
                            // from a user report, so the UI can say where a
                            // price came from and the forecast can weight it.
                            source: 'doe',
                            confidence: 1.0,
                            effectiveAt: $date->copy()->setTime(6, 0),
                        );

                        $counts['written']++;
                    } catch (\Throwable $exception) {
                        // recordPrice refuses an implausible price and one
                        // older than what is stored. Both are expected on a
                        // backfill and neither should cost the rest of the run.
                        $counts['unmappable']++;
                        $this->line("  <fg=yellow>skipped</> {$station->name} {$grade}: {$exception->getMessage()}");
                    }
                }
            }
        });

        $this->report($counts, $unmatched, $date);

        // A run that matched nothing is a failure even though it threw no
        // exception: it means the matcher is broken or the two databases
        // describe different networks, and a zero-write success would hide it.
        if ($counts['rows'] > 0 && $counts['written'] === 0) {
            $this->error('No prices were written. Check station matching above.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Confirm the scraper's database is configured and reachable.
     *
     * Checked up front and reported plainly: the alternative is a PDO
     * exception naming a connection most people have never heard of.
     */
    private function doeDatabaseReachable(): bool
    {
        try {
            DB::connection('doe')->getPdo();

            return true;
        } catch (QueryException|\PDOException $exception) {
            $this->error('Cannot reach the scraper database on the `doe` connection.');
            $this->line('  Set DOE_DB_HOST, DOE_DB_DATABASE, DOE_DB_USERNAME and DOE_DB_PASSWORD.');
            $this->line("  {$exception->getMessage()}");

            return false;
        }
    }

    private function resolveDate(): ?Carbon
    {
        if ($this->option('date')) {
            return Carbon::parse((string) $this->option('date'))->startOfDay();
        }

        $latest = DoePrice::query()->max('price_date');

        return $latest ? Carbon::parse($latest)->startOfDay() : null;
    }

    /**
     * Find the platform station a DOE station refers to.
     *
     * The DOE issues no station identifiers, so this is a name match, and name
     * matching is where a sync like this quietly goes wrong. It is deliberately
     * conservative: brand and city must both agree before a name is even
     * considered. A wrong match writes one station's price onto another, which
     * is worse than no match at all — the unmatched are reported and can be
     * dealt with, a mismatch cannot be seen.
     *
     * @param array<string, int> $counts
     */
    private function matchStation(DoeStation $doeStation, array &$counts): ?GasStation
    {
        static $cache = [];

        $key = $doeStation->id;

        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $match = GasStation::query()
            ->when($doeStation->company, fn ($query) => $query->whereHas(
                'brand',
                fn ($brand) => $brand->where('name', 'like', '%'.$doeStation->company.'%'),
            ))
            ->when($doeStation->city, fn ($query) => $query->whereHas(
                'city',
                fn ($city) => $city->where('name', 'like', '%'.$doeStation->city.'%'),
            ))
            ->when($doeStation->name, fn ($query) => $query->where(
                'name',
                'like',
                '%'.$this->significantPart($doeStation->name).'%',
            ))
            ->first();

        if ($match !== null) {
            $counts['matched']++;
            $cache[$key] = $match;

            return $match;
        }

        if (! $this->option('create-missing') || $this->option('dry-run')) {
            $cache[$key] = null;

            return null;
        }

        $created = $this->createStation($doeStation);

        if ($created !== null) {
            $counts['created']++;
        }

        $cache[$key] = $created;

        return $created;
    }

    /**
     * The distinctive part of a station name.
     *
     * "Petron Shaw Boulevard" and "Shaw Boulevard" are the same site listed two
     * ways. Matching on the whole string finds neither, because the platform
     * stores the branch and the DOE stores the brand and the branch.
     */
    private function significantPart(string $name): string
    {
        $withoutBrand = preg_replace(
            '/^(petron|shell|caltex|seaoil|unioil|cleanfuel|phoenix|jetti|flying\s*v|total(energies)?)\s+/i',
            '',
            trim($name),
        );

        return trim($withoutBrand ?: $name);
    }

    /**
     * Create a platform station from a DOE listing.
     *
     * Only under --create-missing. A DOE row carries a brand name and a city
     * name as free text, and both have to resolve to real rows here — a
     * station attached to no brand or no city breaks the directory filters.
     */
    private function createStation(DoeStation $doeStation): ?GasStation
    {
        $brand = Brand::query()
            ->where('name', 'like', '%'.$doeStation->company.'%')
            ->first();

        $city = City::query()
            ->where('name', 'like', '%'.$doeStation->city.'%')
            ->first();

        if ($brand === null || $city === null) {
            $this->line(sprintf(
                '  <fg=yellow>cannot create</> %s — %s: no %s on record',
                $doeStation->company,
                $doeStation->city ?? 'unknown city',
                $brand === null ? 'brand' : 'city',
            ));

            return null;
        }

        return GasStation::create([
            'brand_id' => $brand->getKey(),
            'city_id' => $city->getKey(),
            'name' => $doeStation->label,
            'slug' => Str::slug($doeStation->label.'-'.$doeStation->city.'-'.$doeStation->id),
            'address_line' => $doeStation->display_address,
            'latitude' => $doeStation->latitude,
            'longitude' => $doeStation->longitude,
            'status' => 'active',
        ]);
    }

    /**
     * @param array<string, int> $counts
     * @param list<string> $unmatched
     */
    private function report(array $counts, array $unmatched, Carbon $date): void
    {
        $this->newLine();
        $this->table(
            ['', 'Count'],
            [
                ['DOE price rows', $counts['rows']],
                ['Stations matched', $counts['matched']],
                ['Stations created', $counts['created']],
                ['Stations unmatched', $counts['unmatched']],
                ['Prices written', $counts['written']],
                ['Grades skipped', $counts['unmappable']],
            ],
        );

        if ($unmatched !== []) {
            $sample = array_slice(array_unique($unmatched), 0, 10);
            $this->newLine();
            $this->warn('Unmatched DOE stations (first '.count($sample).'):');
            foreach ($sample as $name) {
                $this->line("  • {$name}");
            }
            $this->line('  Re-run with --create-missing to add them to the directory.');
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->comment('Dry run — nothing was written.');

            return;
        }

        $this->newLine();
        $this->info("Done. Prices for {$date->toDateString()} are in the platform model.");
        $this->line('  php artisan fip:forecast   # regenerate forecasts from the new history');
    }
}
