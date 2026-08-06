<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pricing\Models\FuelType;
use App\Domain\Pricing\Models\PriceAdvisory;
use App\Domain\Pricing\Services\PriceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Imports a DOE weekly adjustment from a local file and applies it to station
 * prices.
 *
 * This is the sibling of fip:import-advisories, which fetches the live DOE feed
 * over HTTP. That command is the production path and needs DOE_FEED_URL set; it
 * cannot run in an environment without network access to the feed, which is
 * every fresh staging box. This one takes the same data from a CSV or JSON file
 * so a staging environment can be moved forward on demand and by hand.
 *
 * History is preserved by construction: the adjustment is written as a
 * PriceAdvisory and applied through PriceService, which appends to
 * fuel_price_history on every change. Nothing here writes station_prices
 * directly — doing so would update the current price and leave the series with
 * a hole exactly where the change happened.
 */
class ImportDoeWeeklyPrices extends Command
{
    protected $signature = 'fip:import-doe
                            {file? : CSV or JSON file of adjustments (defaults to the bundled sample)}
                            {--week= : Week the adjustment takes effect (defaults to this week)}
                            {--dry-run : Parse and report without writing}';

    protected $description = 'Import a DOE weekly price adjustment from a file into station prices';

    /** Ships with the app so a fresh staging box has something to import. */
    private const SAMPLE = 'database/data/doe-weekly-sample.csv';

    public function handle(PriceService $prices): int
    {
        $path = $this->argument('file') ?? base_path(self::SAMPLE);

        if (! is_file($path)) {
            $this->error("No such file: {$path}");

            return self::FAILURE;
        }

        $week = $this->option('week') !== null
            ? Carbon::parse($this->option('week'))->startOfWeek()
            : now()->startOfWeek();

        $rows = str_ends_with(strtolower($path), '.json')
            ? $this->parseJson($path)
            : $this->parseCsv($path);

        if ($rows === []) {
            $this->warn('No adjustment rows found in the file.');

            return self::SUCCESS;
        }

        $effectiveAt = $week->copy()
            ->addDays((int) config('fip.forecast.effective_day_of_week') - 1)
            ->setTimeFromTimeString((string) config('fip.forecast.effective_time'));

        $this->line("Week of {$week->toDateString()}, effective {$effectiveAt->toDateTimeString()}");

        $applied = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            $fuelType = FuelType::where('code', $row['fuel_code'])->first();

            if ($fuelType === null) {
                $this->warn("  skipped unknown fuel code [{$row['fuel_code']}]");
                $skipped++;

                continue;
            }

            $this->line(sprintf(
                '  %-22s %-10s %s%.2f/L',
                $fuelType->name,
                $row['direction'],
                $row['change_amount'] >= 0 ? '+' : '-',
                abs($row['change_amount']),
            ));

            if ($this->option('dry-run')) {
                continue;
            }

            // Looked up rather than updateOrCreate'd on the same attributes.
            // week_start casts to 'date' but stores a full datetime, so an
            // equality match against '2026-08-03' misses '2026-08-03 00:00:00'
            // and a re-import silently stacks a second advisory for the week.
            // The unique index does not catch it either: region_id is null, and
            // SQL treats nulls in a unique index as distinct. whereDate
            // compares the day on both MySQL and SQLite.
            $advisory = PriceAdvisory::query()
                ->where('fuel_type_id', $fuelType->getKey())
                ->whereNull('region_id')
                ->whereDate('week_start', $week->toDateString())
                ->first() ?? new PriceAdvisory([
                    'fuel_type_id' => $fuelType->getKey(),
                    'region_id' => null,
                    'week_start' => $week->toDateString(),
                ]);

            $advisory->fill([
                'effective_at' => $effectiveAt,
                'change_amount' => $row['change_amount'],
                'direction' => $row['direction'],
                'source' => 'doe',
                'source_url' => 'file://'.basename($path),
                'notes' => $row['notes'] ?? null,
            ])->save();

            $applied += $prices->applyAdvisory($advisory);
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run complete — nothing was written.');

            return self::SUCCESS;
        }

        $this->info("Applied to {$applied} station prices.".($skipped > 0 ? " {$skipped} row(s) skipped." : ''));

        return self::SUCCESS;
    }

    /**
     * Columns: fuel_code, change_amount, notes. Direction is derived from the
     * sign rather than trusted from the file, so a row saying "increase" with a
     * negative amount cannot record a contradiction.
     *
     * @return list<array{fuel_code:string, change_amount:float, direction:string, notes:?string}>
     */
    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return [];
        }

        $header = array_map(static fn ($column) => strtolower(trim((string) $column)), $header);
        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            // fgetcsv gives [null] for a blank line and false at EOF, never an
            // empty array — so the first column being null is the only blank
            // case there is.
            if (($line[0] ?? null) === null) {
                continue;
            }

            $record = array_combine($header, array_pad($line, count($header), null));

            if (empty($record['fuel_code'])) {
                continue;
            }

            $rows[] = $this->normalise(
                (string) $record['fuel_code'],
                (float) ($record['change_amount'] ?? 0),
                isset($record['notes']) ? trim((string) $record['notes']) : null,
            );
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @return list<array{fuel_code:string, change_amount:float, direction:string, notes:?string}>
     */
    private function parseJson(string $path): array
    {
        $decoded = json_decode((string) file_get_contents($path), true);

        if (! is_array($decoded)) {
            $this->error('The JSON file did not decode to an array.');

            return [];
        }

        // Accepts either a bare list or {"adjustments": [...]}.
        $items = $decoded['adjustments'] ?? $decoded;

        $rows = [];

        foreach ((array) $items as $row) {
            if (! is_array($row) || empty($row['fuel_code'])) {
                continue;
            }

            $rows[] = $this->normalise(
                (string) $row['fuel_code'],
                (float) ($row['change_amount'] ?? 0),
                isset($row['notes']) ? (string) $row['notes'] : null,
            );
        }

        return $rows;
    }

    /**
     * @return array{fuel_code:string, change_amount:float, direction:string, notes:?string}
     */
    private function normalise(string $fuelCode, float $change, ?string $notes): array
    {
        $change = round($change, 4);

        return [
            'fuel_code' => trim($fuelCode),
            'change_amount' => $change,
            'direction' => match (true) {
                $change > 0 => 'increase',
                $change < 0 => 'decrease',
                default => 'no_change',
            },
            'notes' => $notes !== null && $notes !== '' ? $notes : null,
        ];
    }
}
