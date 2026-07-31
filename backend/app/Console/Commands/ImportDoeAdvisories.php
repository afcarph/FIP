<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pricing\Models\FuelType;
use App\Domain\Pricing\Models\PriceAdvisory;
use App\Domain\Pricing\Services\PriceService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

/**
 * Pulls the weekly DOE oil price adjustment and applies it platform-wide.
 *
 * The feed is scraped rather than consumed from an API because the DOE
 * publishes advisories as an HTML table; the parser is deliberately tolerant
 * and skips rows it cannot read rather than aborting the whole import.
 */
class ImportDoeAdvisories extends Command
{
    protected $signature = 'fip:import-advisories
                            {--week= : Week start date (defaults to this week)}
                            {--dry-run : Parse and report without writing}';

    protected $description = 'Import the weekly DOE pump-price adjustment and apply it to station prices';

    public function handle(PriceService $prices): int
    {
        $url = config('services.doe.feed_url');

        if (empty($url)) {
            $this->error('DOE_FEED_URL is not configured.');

            return self::FAILURE;
        }

        $week = $this->option('week') !== null
            ? Carbon::parse($this->option('week'))->startOfWeek()
            : now()->startOfWeek();

        $response = Http::timeout(30)->retry(2, 500)->get($url);

        if ($response->failed()) {
            $this->error("DOE feed returned HTTP {$response->status()}.");

            return self::FAILURE;
        }

        $rows = $this->parse($response->body());

        if ($rows === []) {
            $this->warn('No adjustment rows were found in the feed.');

            return self::SUCCESS;
        }

        $applied = 0;

        foreach ($rows as $row) {
            $fuelType = FuelType::where('code', $row['fuel_code'])->first();

            if ($fuelType === null) {
                $this->warn("Skipping unknown fuel code [{$row['fuel_code']}].");

                continue;
            }

            $this->line(sprintf(
                '%-20s %s ₱%.2f/L',
                $fuelType->name,
                $row['direction'],
                abs($row['change_amount']),
            ));

            if ($this->option('dry-run')) {
                continue;
            }

            $advisory = PriceAdvisory::updateOrCreate(
                ['fuel_type_id' => $fuelType->getKey(), 'region_id' => null, 'week_start' => $week->toDateString()],
                [
                    'effective_at' => $week->copy()
                        ->addDays((int) config('fip.forecast.effective_day_of_week') - 1)
                        ->setTimeFromTimeString(config('fip.forecast.effective_time')),
                    'change_amount' => $row['change_amount'],
                    'direction' => $row['direction'],
                    'source' => 'doe',
                    'source_url' => $url,
                    'notes' => $row['notes'] ?? null,
                ],
            );

            $applied += $prices->applyAdvisory($advisory);
        }

        $this->info($this->option('dry-run')
            ? 'Dry run complete — nothing was written.'
            : "Applied advisories to {$applied} station prices.");

        return self::SUCCESS;
    }

    /**
     * Extract adjustment rows from the DOE advisory page.
     *
     * @return array<int, array{fuel_code: string, change_amount: float, direction: string, notes?: string}>
     */
    private function parse(string $html): array
    {
        $map = [
            'gasoline' => 'gasoline_ron95',
            'diesel' => 'diesel',
            'kerosene' => 'kerosene',
        ];

        $rows = [];

        // Rows read like "Gasoline +0.50" or "Diesel -1.20 per litre".
        if (preg_match_all(
            '/(gasoline|diesel|kerosene)[^0-9+\-]{0,40}([+\-]?\s?\d+\.\d{2})/i',
            strip_tags($html),
            $matches,
            PREG_SET_ORDER,
        ) === false) {
            return [];
        }

        foreach ($matches as $match) {
            $label = mb_strtolower($match[1]);
            $amount = (float) str_replace(' ', '', $match[2]);

            if (! isset($map[$label])) {
                continue;
            }

            $rows[$map[$label]] = [
                'fuel_code' => $map[$label],
                'change_amount' => $amount,
                'direction' => match (true) {
                    $amount > 0.001 => 'increase',
                    $amount < -0.001 => 'rollback',
                    default => 'no_change',
                },
            ];
        }

        return array_values($rows);
    }
}
