<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Pricing\Models\MarketIndicator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Refreshes the exogenous regressors the forecast model depends on.
 *
 * Each source is fetched independently so one provider being down does not
 * block the rest — a partial refresh is better than none.
 */
class RefreshMarketIndicators extends Command
{
    protected $signature = 'fip:refresh-indicators';

    protected $description = 'Fetch crude oil benchmarks and the USD/PHP rate';

    public function handle(): int
    {
        $refreshed = 0;

        $refreshed += $this->refreshExchangeRate() ? 1 : 0;
        $refreshed += $this->refreshCrude();

        $this->info("Refreshed {$refreshed} indicator series.");

        return self::SUCCESS;
    }

    private function refreshExchangeRate(): bool
    {
        $baseUrl = config('services.exchange_rate.base_url');
        $key = config('services.exchange_rate.key');

        $response = Http::timeout(15)->retry(2, 500)->get("{$baseUrl}/latest", array_filter([
            'base' => 'USD',
            'symbols' => 'PHP',
            'access_key' => $key,
        ]));

        $rate = $response->json('rates.PHP');

        if ($response->failed() || $rate === null) {
            $this->warn('USD/PHP rate unavailable.');

            return false;
        }

        MarketIndicator::updateOrCreate(
            ['indicator' => 'usd_php', 'observed_on' => now()->toDateString()],
            ['value' => (float) $rate, 'unit' => 'PHP', 'source' => 'exchangerate.host'],
        );

        $this->line(sprintf('USD/PHP  %.4f', (float) $rate));

        return true;
    }

    /**
     * Crude benchmarks come from a commercial feed in production. Without
     * credentials the command records nothing rather than inventing values —
     * a fabricated regressor is worse than a missing one.
     */
    private function refreshCrude(): int
    {
        if (empty(config('services.exchange_rate.key'))) {
            $this->warn('No commodity feed credentials configured; skipping crude benchmarks.');

            return 0;
        }

        $count = 0;

        foreach (['dubai_crude' => 'DUBAI', 'brent' => 'BRENT', 'wti' => 'WTI'] as $indicator => $symbol) {
            $response = Http::timeout(15)->get(config('services.exchange_rate.base_url').'/commodity', [
                'symbol' => $symbol,
                'access_key' => config('services.exchange_rate.key'),
            ]);

            $value = $response->json('price');

            if ($response->failed() || $value === null) {
                $this->warn("{$symbol} unavailable.");

                continue;
            }

            MarketIndicator::updateOrCreate(
                ['indicator' => $indicator, 'observed_on' => now()->toDateString()],
                ['value' => (float) $value, 'unit' => 'USD/bbl', 'source' => 'commodity-feed'],
            );

            $this->line(sprintf('%-12s %.2f USD/bbl', $symbol, (float) $value));
            $count++;
        }

        return $count;
    }
}
