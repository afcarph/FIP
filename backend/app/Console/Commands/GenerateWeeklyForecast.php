<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ai\Services\PriceForecastService;
use App\Domain\Notification\Services\NotificationService;
use App\Domain\User\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GenerateWeeklyForecast extends Command
{
    protected $signature = 'fip:forecast
                            {--week= : ISO date of the week to forecast (defaults to next week)}
                            {--notify : Push the forecast to subscribed users}';

    protected $description = 'Generate the weekly pump-price forecast for every active fuel type';

    public function handle(PriceForecastService $forecasts, NotificationService $notifications): int
    {
        $week = $this->option('week') !== null ? Carbon::parse($this->option('week')) : null;

        $this->info('Generating forecasts…');

        $results = $forecasts->generateWeekly($week);

        if ($results === []) {
            $this->error('No forecasts were produced — check the AI service.');

            return self::FAILURE;
        }

        $this->table(
            ['Fuel type', 'Direction', 'Change (₱/L)', 'Confidence'],
            collect($results)->map(static fn ($f) => [
                $f->fuelType?->name,
                $f->direction,
                number_format($f->change_amount, 2),
                number_format($f->confidence * 100, 1).'%',
            ])->all(),
        );

        if (! $this->option('notify')) {
            return self::SUCCESS;
        }

        $sent = 0;

        User::query()
            ->whereHas('preferences', fn ($q) => $q->where('notify_ai_insights', true))
            ->with('preferences')
            ->chunkById(500, function ($users) use ($results, $notifications, &$sent): void {
                foreach ($users as $user) {
                    // Send only the forecast for the fuel the user actually buys.
                    $forecast = collect($results)->firstWhere('fuel_type_id', $user->preferences?->preferred_fuel_type_id)
                        ?? $results[0];

                    if (! $forecast->isConfident()) {
                        continue;
                    }

                    $notifications->send($user, 'price_forecast', 'forecast', [
                        'template' => 'price_forecast_weekly',
                        'variables' => [
                            'direction' => ucfirst($forecast->direction),
                            'amount' => number_format(abs($forecast->change_amount), 2),
                            'date' => $forecast->forecast_for?->toFormattedDateString(),
                            'confidence' => (int) round($forecast->confidence * 100),
                        ],
                        'data' => ['fuel_type_id' => $forecast->fuel_type_id],
                        'action_url' => '/forecasts',
                    ]);

                    $sent++;
                }
            });

        $this->info("Notified {$sent} users.");

        return self::SUCCESS;
    }
}
