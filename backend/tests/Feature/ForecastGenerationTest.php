<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ai\Models\PriceForecast;
use App\Domain\Ai\Services\PriceForecastService;
use App\Domain\Pricing\Models\FuelType;
use App\Domain\Pricing\Services\PriceService;
use App\Domain\Station\Models\GasStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Forecasting is a request to the AI service, so these tests are about the
 * shape of what we send it. A forecast that never leaves the building is not
 * worth asserting on, and the failure that mattered in practice was a payload
 * the service rejected outright.
 */
class ForecastGenerationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    private function fakeForecastResponse(): void
    {
        Http::fake(['*/predict/price' => Http::response([
            'direction' => 'increase',
            'change_amount' => 0.55,
            'predicted_price' => 63.40,
            'lower_bound' => 62.90,
            'upper_bound' => 63.90,
            'confidence' => 0.62,
            'drivers' => [['factor' => 'dubai_crude', 'weight' => 0.6, 'value' => 'firmer']],
            'narrative' => 'Crude firmed through the week.',
        ], 200)]);
    }

    private function seedHistory(FuelType $fuelType, int $weeks = 8): void
    {
        $station = GasStation::factory()->create();
        $prices = app(PriceService::class);

        for ($i = $weeks; $i >= 0; $i--) {
            $prices->recordPrice(
                station: $station,
                fuelTypeId: $fuelType->getKey(),
                price: 60.0 + ($weeks - $i) * 0.3,
                source: 'import',
                confidence: 0.9,
                effectiveAt: now()->startOfWeek()->subWeeks($i),
            );
        }
    }

    public function test_indicators_are_sent_as_an_object_even_when_there_are_none(): void
    {
        $this->fakeForecastResponse();
        $fuelType = FuelType::where('code', 'gasoline_ron95')->firstOrFail();
        $this->seedHistory($fuelType);

        app(PriceForecastService::class)->generateFor($fuelType, now()->startOfWeek()->addWeek());

        Http::assertSent(function ($request) {
            // The service's schema types indicators as an object. PHP encodes an
            // empty array as [], which the schema rejects with a 422 — so a
            // staging box with no market indicators, or any deploy without an
            // exchange rate key, failed every forecast for a reason that read as
            // missing data rather than a wrong shape.
            $json = json_encode($request->data());

            return str_contains((string) $json, '"indicators":{}');
        });
    }

    public function test_it_stores_a_forecast_for_the_requested_week(): void
    {
        $this->fakeForecastResponse();
        $fuelType = FuelType::where('code', 'gasoline_ron95')->firstOrFail();
        $this->seedHistory($fuelType);

        $week = now()->startOfWeek()->addWeek();
        $forecast = app(PriceForecastService::class)->generateFor($fuelType, $week);

        $this->assertSame('increase', $forecast->direction);
        $this->assertEqualsWithDelta(0.55, (float) $forecast->change_amount, 0.001);
        $this->assertEqualsWithDelta(63.40, (float) $forecast->predicted_price, 0.001);
        $this->assertSame($week->toDateString(), $forecast->forecast_for->toDateString());
    }

    public function test_it_sends_the_price_history_it_has(): void
    {
        $this->fakeForecastResponse();
        $fuelType = FuelType::where('code', 'gasoline_ron95')->firstOrFail();
        $this->seedHistory($fuelType, 6);

        app(PriceForecastService::class)->generateFor($fuelType, now()->startOfWeek()->addWeek());

        Http::assertSent(function ($request) {
            $history = $request->data()['history'] ?? [];

            // Each point must carry the keys the schema names. Sending
            // recorded_on/price instead of date/avg_price would be rejected the
            // same way the empty indicators were.
            return $history !== []
                && array_key_exists('date', $history[0])
                && array_key_exists('avg_price', $history[0]);
        });
    }

    public function test_the_weekly_command_writes_a_forecast_per_fuel_type(): void
    {
        $this->fakeForecastResponse();

        foreach (['gasoline_ron91', 'gasoline_ron95', 'diesel'] as $code) {
            $this->seedHistory(FuelType::where('code', $code)->firstOrFail(), 4);
        }

        $this->artisan('fip:forecast')->assertExitCode(0);

        // Every active gasoline and diesel type is forecast, so the count is at
        // least the three seeded above.
        $this->assertGreaterThanOrEqual(3, PriceForecast::count());
    }

    public function test_a_failing_ai_service_is_reported_as_a_failed_run(): void
    {
        Http::fake(['*/predict/price' => Http::response(['detail' => 'boom'], 500)]);
        $this->seedHistory(FuelType::where('code', 'diesel')->firstOrFail(), 4);

        // Individual fuel types are caught and logged so one failure does not
        // cost the rest, but a run that produced nothing exits non-zero: this
        // is scheduled work, and a silent success would hide an AI service that
        // has been down for a week.
        $this->artisan('fip:forecast')->assertExitCode(1);

        $this->assertSame(0, PriceForecast::count());
    }

    public function test_one_failing_fuel_type_does_not_cost_the_others(): void
    {
        $calls = 0;

        Http::fake(function () use (&$calls) {
            $calls++;

            return $calls === 1
                ? Http::response(['detail' => 'boom'], 500)
                : Http::response([
                    'direction' => 'no_change',
                    'change_amount' => 0,
                    'confidence' => 0.4,
                ], 200);
        });

        foreach (['gasoline_ron91', 'gasoline_ron95', 'diesel'] as $code) {
            $this->seedHistory(FuelType::where('code', $code)->firstOrFail(), 4);
        }

        $this->artisan('fip:forecast')->assertExitCode(0);

        // The first call failed; the run still produced forecasts for the rest.
        $this->assertGreaterThan(0, PriceForecast::count());
    }
}
