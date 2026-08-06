<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pricing\Models\FuelPriceHistory;
use App\Domain\Pricing\Models\FuelType;
use App\Domain\Pricing\Models\PriceAdvisory;
use App\Domain\Pricing\Models\StationPrice;
use App\Domain\Pricing\Services\PriceService;
use App\Domain\Station\Models\GasStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The importer's job is to move prices forward without losing what they were.
 * station_prices keeps only the current price, so "preserved history" means
 * fuel_price_history gained a row — testing the visible price alone would pass
 * against an implementation that quietly overwrote the series.
 */
class DoeImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    private function station(): GasStation
    {
        return GasStation::factory()->create();
    }

    private function seedCurrentPrice(GasStation $station, FuelType $fuelType, float $price): void
    {
        app(PriceService::class)->recordPrice(
            station: $station,
            fuelTypeId: $fuelType->getKey(),
            price: $price,
            source: 'import',
            confidence: 0.9,
            effectiveAt: now()->subWeek(),
        );
    }

    private function writeCsv(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'doe').'.csv';
        file_put_contents($path, $contents);

        return $path;
    }

    public function test_it_applies_an_increase_to_the_current_price(): void
    {
        $fuelType = FuelType::where('code', 'diesel')->firstOrFail();
        $station = $this->station();
        $this->seedCurrentPrice($station, $fuelType, 56.00);

        $file = $this->writeCsv("fuel_code,change_amount,notes\ndiesel,1.25,Crude firmer\n");

        $this->artisan('fip:import-doe', ['file' => $file])->assertExitCode(0);

        $current = StationPrice::where('station_id', $station->getKey())
            ->where('fuel_type_id', $fuelType->getKey())
            ->firstOrFail();

        $this->assertEqualsWithDelta(57.25, (float) $current->price, 0.001);
        $this->assertEqualsWithDelta(56.00, (float) $current->previous_price, 0.001);
    }

    public function test_it_preserves_the_earlier_price_in_history(): void
    {
        $fuelType = FuelType::where('code', 'diesel')->firstOrFail();
        $station = $this->station();
        $this->seedCurrentPrice($station, $fuelType, 56.00);

        $before = FuelPriceHistory::where('fuel_type_id', $fuelType->getKey())->count();

        $file = $this->writeCsv("fuel_code,change_amount\ndiesel,1.25\n");
        $this->artisan('fip:import-doe', ['file' => $file])->assertExitCode(0);

        $prices = FuelPriceHistory::where('fuel_type_id', $fuelType->getKey())
            ->orderBy('recorded_at')
            ->pluck('price')
            ->map(static fn ($price) => round((float) $price, 2))
            ->all();

        // The old price is still there alongside the new one. An importer that
        // updated station_prices in place would leave only 57.25.
        $this->assertGreaterThan($before, count($prices));
        $this->assertContains(56.00, $prices);
        $this->assertContains(57.25, $prices);
    }

    public function test_it_derives_direction_from_the_sign_rather_than_the_file(): void
    {
        $fuelType = FuelType::where('code', 'diesel')->firstOrFail();
        $this->seedCurrentPrice($this->station(), $fuelType, 56.00);

        $file = $this->writeCsv("fuel_code,change_amount\ndiesel,-0.40\n");
        $this->artisan('fip:import-doe', ['file' => $file])->assertExitCode(0);

        $advisory = PriceAdvisory::where('fuel_type_id', $fuelType->getKey())->firstOrFail();

        $this->assertSame('decrease', $advisory->direction);
        $this->assertEqualsWithDelta(-0.40, (float) $advisory->change_amount, 0.001);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $fuelType = FuelType::where('code', 'diesel')->firstOrFail();
        $station = $this->station();
        $this->seedCurrentPrice($station, $fuelType, 56.00);

        $file = $this->writeCsv("fuel_code,change_amount\ndiesel,1.25\n");
        $this->artisan('fip:import-doe', ['file' => $file, '--dry-run' => true])->assertExitCode(0);

        $current = StationPrice::where('station_id', $station->getKey())->firstOrFail();

        $this->assertEqualsWithDelta(56.00, (float) $current->price, 0.001);
        $this->assertSame(0, PriceAdvisory::count());
    }

    public function test_importing_the_same_week_twice_corrects_rather_than_stacks(): void
    {
        $fuelType = FuelType::where('code', 'diesel')->firstOrFail();
        $this->seedCurrentPrice($this->station(), $fuelType, 56.00);

        $week = now()->startOfWeek()->toDateString();

        $this->artisan('fip:import-doe', [
            'file' => $this->writeCsv("fuel_code,change_amount\ndiesel,1.00\n"),
            '--week' => $week,
        ])->assertExitCode(0);

        $this->artisan('fip:import-doe', [
            'file' => $this->writeCsv("fuel_code,change_amount\ndiesel,1.50\n"),
            '--week' => $week,
        ])->assertExitCode(0);

        $advisories = PriceAdvisory::where('fuel_type_id', $fuelType->getKey())->get();

        $this->assertCount(1, $advisories, 'A corrected week should update its advisory, not add another.');
        $this->assertEqualsWithDelta(1.50, (float) $advisories->first()->change_amount, 0.001);
    }

    public function test_an_unknown_fuel_code_is_skipped_without_failing_the_run(): void
    {
        $file = $this->writeCsv("fuel_code,change_amount\nnot_a_fuel,1.00\ndiesel,0.50\n");

        $this->seedCurrentPrice($this->station(), FuelType::where('code', 'diesel')->firstOrFail(), 56.00);

        // One bad row in a weekly feed should not cost the other six.
        $this->artisan('fip:import-doe', ['file' => $file])->assertExitCode(0);

        $this->assertSame(1, PriceAdvisory::count());
    }

    public function test_a_missing_file_fails_loudly(): void
    {
        $this->artisan('fip:import-doe', ['file' => '/tmp/definitely-not-here.csv'])
            ->assertExitCode(1);
    }

    public function test_it_reads_json_as_well_as_csv(): void
    {
        $fuelType = FuelType::where('code', 'diesel')->firstOrFail();
        $this->seedCurrentPrice($this->station(), $fuelType, 56.00);

        $path = tempnam(sys_get_temp_dir(), 'doe').'.json';
        file_put_contents($path, json_encode([
            'adjustments' => [['fuel_code' => 'diesel', 'change_amount' => 0.65]],
        ]));

        $this->artisan('fip:import-doe', ['file' => $path])->assertExitCode(0);

        $this->assertEqualsWithDelta(
            0.65,
            (float) PriceAdvisory::where('fuel_type_id', $fuelType->getKey())->firstOrFail()->change_amount,
            0.001,
        );
    }
}
