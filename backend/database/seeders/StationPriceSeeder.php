<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Pricing\Models\FuelPriceHistory;
use App\Domain\Pricing\Models\FuelType;
use App\Domain\Pricing\Services\PriceService;
use App\Domain\Station\Models\GasStation;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/**
 * Prices for every seeded station, with enough weekly history behind them that
 * the forecast, the trend chart and the advisor have something to work from.
 *
 * Everything goes through PriceService::recordPrice rather than inserting rows
 * directly. station_prices holds only the current price per station and fuel;
 * the series lives in fuel_price_history, and recordPrice is what writes both.
 * Seeding the table directly would leave a map that looks populated and a
 * forecast with nothing to read.
 */
class StationPriceSeeder extends Seeder
{
    /** Weeks of history to lay down before today. */
    private const WEEKS_OF_HISTORY = 16;

    /**
     * Opening national average per fuel code, in pesos per litre, roughly at
     * Philippine levels. The walk below moves these week to week.
     *
     * @var array<string, float>
     */
    private const BASE_PRICE = [
        'gasoline_ron91' => 58.20,
        'gasoline_ron95' => 62.40,
        'gasoline_ron97' => 66.10,
        'diesel' => 56.85,
        'diesel_premium' => 60.30,
        'kerosene' => 63.75,
        'lpg_auto' => 38.90,
    ];

    /**
     * Per-brand positioning against the national average. Real chains do not
     * price identically, and a demo where every station matches makes the
     * "cheapest nearby" feature look broken.
     *
     * @var array<string, float>
     */
    private const BRAND_OFFSET = [
        'Petron' => 0.45,
        'Shell' => 0.60,
        'Caltex' => 0.35,
        'TotalEnergies' => 0.25,
        'SEAOIL' => -0.35,
        'Unioil' => -0.55,
        'CleanFuel' => -0.85,
        'Phoenix Petroleum' => -0.20,
        'Jetti' => -0.65,
        'Flying V' => -0.75,
    ];

    public function run(PriceService $prices): void
    {
        $stations = GasStation::with('brand', 'city.province')->get();

        if ($stations->isEmpty()) {
            $this->command->warn('No stations to price — run GasStationSeeder first.');

            return;
        }

        // recordPrice refuses a price older than the one already stored, and a
        // refused write records no history either. Re-running would therefore
        // walk sixteen weeks of backdated prices past a newer current price and
        // report success while writing nothing. Say so instead.
        if (FuelPriceHistory::query()->exists()) {
            $this->command->warn(
                'Price history already present — skipping. Re-seeding prices needs a clean '
                .'fuel_price_history, or use `php artisan fip:import-doe` to move prices forward.',
            );

            return;
        }

        $fuelTypes = FuelType::whereIn('code', array_keys(self::BASE_PRICE))->get();
        $weekStart = Carbon::now()->startOfWeek()->subWeeks(self::WEEKS_OF_HISTORY);

        // A deterministic seed keeps the series identical between runs, so a
        // screenshot taken today still matches the data next week.
        mt_srand(20260806);

        $written = 0;

        foreach ($fuelTypes as $fuelType) {
            $national = self::BASE_PRICE[$fuelType->code];

            for ($week = 0; $week <= self::WEEKS_OF_HISTORY; $week++) {
                // A small weekly drift with occasional larger moves, which is
                // roughly how DOE adjustments behave: mostly ±0.50, sometimes
                // a swing worth forecasting.
                $shock = mt_rand(0, 100) < 15 ? (mt_rand(-180, 180) / 100) : 0.0;
                $national = round($national + (mt_rand(-45, 50) / 100) + $shock, 4);
                $effectiveAt = $weekStart->copy()->addWeeks($week)->setTime(6, 0);

                // Nothing in the future: a price effective next Tuesday would
                // sit in the history the forecast is meant to predict.
                if ($effectiveAt->isFuture()) {
                    break;
                }

                foreach ($stations as $station) {
                    $offset = self::BRAND_OFFSET[$station->brand?->name] ?? 0.0;

                    // Two decimals: pumps do not price in fractions of a centavo,
                    // and unrounded values make every figure on screen look wrong.
                    $price = round($national + $offset + (mt_rand(-25, 25) / 100), 2);

                    $prices->recordPrice(
                        station: $station,
                        fuelTypeId: $fuelType->getKey(),
                        price: $price,
                        source: 'import',
                        confidence: 0.9,
                        effectiveAt: $effectiveAt,
                    );

                    $written++;
                }
            }
        }

        $this->command->info(sprintf(
            'Prices: %d records across %d stations and %d fuel types, %d weeks of history.',
            $written,
            $stations->count(),
            $fuelTypes->count(),
            self::WEEKS_OF_HISTORY,
        ));
    }
}
