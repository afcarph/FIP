<?php

declare(strict_types=1);

namespace App\Domain\Ai\Services;

use App\Domain\Ai\Models\AiModel;
use App\Domain\Ai\Models\PriceForecast;
use App\Domain\Pricing\Models\FuelType;
use App\Domain\Pricing\Models\MarketIndicator;
use App\Domain\Pricing\Models\PriceAdvisory;
use App\Domain\Pricing\Repositories\PriceRepository;
use App\Services\External\AiServiceClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the weekly pump-price forecast.
 *
 * Laravel owns the data assembly and persistence; the Python service owns the
 * modelling. Assembling the feature frame here keeps a single definition of
 * "what the model sees" and lets us replay any historical forecast.
 */
final readonly class PriceForecastService
{
    private const INDICATORS = ['dubai_crude', 'brent', 'mops_gasoline', 'mops_gasoil', 'usd_php'];

    public function __construct(
        private AiServiceClient $ai,
        private PriceRepository $prices,
    ) {}

    /**
     * Generate and persist forecasts for every active fuel type.
     *
     * @return array<int, PriceForecast>
     */
    public function generateWeekly(?Carbon $forWeek = null): array
    {
        $forWeek ??= $this->nextEffectiveWeek();
        $model = AiModel::activeFor('price_forecast');
        $results = [];

        foreach (FuelType::active()->whereIn('category', ['gasoline', 'diesel'])->get() as $fuelType) {
            try {
                $results[] = $this->generateFor($fuelType, $forWeek, $model);
            } catch (\Throwable $e) {
                Log::error('Price forecast failed', [
                    'fuel_type' => $fuelType->code,
                    'week' => $forWeek->toDateString(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        Cache::forget('forecast:latest');

        return array_filter($results);
    }

    public function generateFor(FuelType $fuelType, Carbon $forWeek, ?AiModel $model = null): PriceForecast
    {
        $response = $this->ai->forecastPrice([
            'fuel_type' => $fuelType->code,
            'fuel_category' => $fuelType->category,
            'forecast_for' => $forWeek->toDateString(),
            'horizon_weeks' => (int) config('fip.forecast.horizon_weeks'),
            'history' => $this->priceHistoryFeature($fuelType),
            'advisories' => $this->advisoryFeature($fuelType),
            'indicators' => $this->indicatorFeature(),
        ]);

        return PriceForecast::updateOrCreate(
            [
                'fuel_type_id' => $fuelType->getKey(),
                'region_id' => null,
                'forecast_for' => $forWeek->toDateString(),
                'generated_at' => now(),
            ],
            [
                'ai_model_id' => $model?->getKey(),
                'direction' => $response['direction'] ?? 'no_change',
                'change_amount' => round((float) ($response['change_amount'] ?? 0), 4),
                'predicted_price' => isset($response['predicted_price']) ? round((float) $response['predicted_price'], 4) : null,
                'lower_bound' => isset($response['lower_bound']) ? round((float) $response['lower_bound'], 4) : null,
                'upper_bound' => isset($response['upper_bound']) ? round((float) $response['upper_bound'], 4) : null,
                'confidence' => round((float) ($response['confidence'] ?? 0), 3),
                'drivers' => $response['drivers'] ?? [],
                'narrative' => $response['narrative'] ?? null,
            ],
        );
    }

    /** The latest forecast per fuel type, cached for the dashboard. */
    public function latest(): array
    {
        return Cache::remember('forecast:latest', (int) config('fip.forecast.cache_ttl'), function (): array {
            return PriceForecast::query()
                ->upcoming()
                ->latestGeneration()
                ->with('fuelType')
                ->get()
                ->groupBy('fuel_type_id')
                ->map(fn ($group) => $group->sortBy('forecast_for')->first())
                ->values()
                ->all();
        });
    }

    /**
     * Score published forecasts against what the DOE actually announced.
     * Back-filling keeps the public accuracy figure honest.
     *
     * @return array{scored: int, mae: float|null, direction_accuracy: float|null}
     */
    public function scoreAgainstActuals(?Carbon $week = null): array
    {
        $week ??= now()->startOfWeek();

        $forecasts = PriceForecast::query()
            ->where('forecast_for', $week->toDateString())
            ->whereNull('actual_change')
            ->get();

        $errors = [];
        $directionHits = 0;

        foreach ($forecasts as $forecast) {
            $advisory = PriceAdvisory::query()
                ->where('fuel_type_id', $forecast->fuel_type_id)
                ->where('week_start', $week->toDateString())
                ->whereNull('region_id')
                ->first();

            if ($advisory === null) {
                continue;
            }

            $actual = (float) $advisory->change_amount;
            $error = abs($forecast->change_amount - $actual);

            $forecast->update(['actual_change' => $actual, 'absolute_error' => round($error, 4)]);

            $errors[] = $error;
            $directionHits += $advisory->direction === $forecast->direction ? 1 : 0;
        }

        $scored = count($errors);

        return [
            'scored' => $scored,
            'mae' => $scored > 0 ? round(array_sum($errors) / $scored, 4) : null,
            'direction_accuracy' => $scored > 0 ? round($directionHits / $scored, 3) : null,
        ];
    }

    /** Published accuracy over the trailing 26 weeks. */
    public function accuracySummary(int $weeks = 26): array
    {
        $rows = PriceForecast::query()
            ->whereNotNull('absolute_error')
            ->where('forecast_for', '>=', now()->subWeeks($weeks)->startOfWeek())
            ->get(['direction', 'actual_change', 'change_amount', 'absolute_error']);

        if ($rows->isEmpty()) {
            return ['samples' => 0, 'mae' => null, 'direction_accuracy' => null];
        }

        $directionHits = $rows->filter(function (PriceForecast $f): bool {
            $actualDirection = match (true) {
                $f->actual_change > 0.0001 => 'increase',
                $f->actual_change < -0.0001 => 'rollback',
                default => 'no_change',
            };

            return $actualDirection === $f->direction;
        })->count();

        return [
            'samples' => $rows->count(),
            'mae' => round((float) $rows->avg('absolute_error'), 4),
            'direction_accuracy' => round($directionHits / $rows->count(), 3),
        ];
    }

    // ------------------------------------------------------- feature frame

    /** Daily national average series, the model's endogenous signal. */
    private function priceHistoryFeature(FuelType $fuelType, int $days = 730): array
    {
        return $this->prices->nationalTrend($fuelType->getKey(), $days);
    }

    /** Realised weekly adjustments — the target variable's own history. */
    private function advisoryFeature(FuelType $fuelType, int $weeks = 104): array
    {
        return $this->prices->advisoryHistory($fuelType->getKey(), $weeks);
    }

    /** Exogenous regressors, aligned on observation date. */
    private function indicatorFeature(int $weeks = 104): array
    {
        $series = [];

        foreach (self::INDICATORS as $indicator) {
            $rows = MarketIndicator::series($indicator, $weeks)->get(['observed_on', 'value']);

            if ($rows->isEmpty()) {
                continue;
            }

            $series[$indicator] = $rows->map(static fn (MarketIndicator $row) => [
                'date' => $row->observed_on->toDateString(),
                'value' => $row->value,
            ])->all();
        }

        return $series;
    }

    /** DOE adjustments land 06:00 Tuesday; forecast that week's Monday. */
    private function nextEffectiveWeek(): Carbon
    {
        return now()->startOfWeek()->addWeek();
    }
}
