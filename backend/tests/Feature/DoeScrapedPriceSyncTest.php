<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pricing\Models\FuelPriceHistory;
use App\Domain\Pricing\Models\FuelType;
use App\Domain\Pricing\Models\StationPrice;
use App\Domain\Station\Models\Brand;
use App\Domain\Station\Models\City;
use App\Domain\Station\Models\GasStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsDoeDatabase;
use Tests\TestCase;

/**
 * The bridge from the scraper's database into the platform's price model.
 *
 * The thing worth testing is not that rows moved — it is that they moved
 * through PriceService, so fuel_price_history gained a row. A sync that wrote
 * station_prices directly would pass a test that only checked the visible
 * price, and leave the forecast with nothing to read.
 */
class DoeScrapedPriceSyncTest extends TestCase
{
    use BuildsDoeDatabase;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
        $this->setUpDoeDatabase();
    }

    private function platformStation(string $brandName = 'Petron', string $cityName = 'Quezon City'): GasStation
    {
        $brand = Brand::where('name', $brandName)->firstOrFail();
        $city = City::where('name', $cityName)->firstOrFail();

        return GasStation::factory()->create([
            'brand_id' => $brand->getKey(),
            'city_id' => $city->getKey(),
            'name' => 'Petron EDSA',
        ]);
    }

    public function test_it_writes_scraped_prices_into_the_platform_model(): void
    {
        $station = $this->platformStation();

        $doe = $this->doeStation(['company' => 'Petron', 'name' => 'Petron EDSA', 'city' => 'Quezon City']);
        $this->doePrice($doe, '2026-08-06', ['ron95' => 62.40, 'diesel' => 56.85, 'ron91' => 58.20]);

        $this->artisan('fip:sync-doe-prices')->assertExitCode(0);

        $ron95 = FuelType::where('code', 'gasoline_ron95')->firstOrFail();

        $price = StationPrice::where('station_id', $station->getKey())
            ->where('fuel_type_id', $ron95->getKey())
            ->firstOrFail();

        $this->assertEqualsWithDelta(62.40, (float) $price->price, 0.001);
    }

    public function test_it_appends_to_the_price_history(): void
    {
        // The whole reason it goes through PriceService. station_prices holds
        // only the current price; the series is what the forecast reads.
        $this->platformStation();

        $doe = $this->doeStation(['company' => 'Petron', 'name' => 'Petron EDSA', 'city' => 'Quezon City']);
        $this->doePrice($doe, '2026-08-06', ['ron95' => 62.40, 'diesel' => 56.85, 'ron91' => null]);

        $this->artisan('fip:sync-doe-prices')->assertExitCode(0);

        $this->assertGreaterThan(0, FuelPriceHistory::count());
    }

    public function test_it_records_the_source_as_doe(): void
    {
        // So the UI can say where a price came from, and so a scraped price is
        // distinguishable from an operator's own entry.
        $this->platformStation();

        $doe = $this->doeStation(['company' => 'Petron', 'name' => 'Petron EDSA', 'city' => 'Quezon City']);
        $this->doePrice($doe, '2026-08-06', ['ron95' => 62.40]);

        $this->artisan('fip:sync-doe-prices')->assertExitCode(0);

        $this->assertSame('doe', StationPrice::firstOrFail()->source);
    }

    public function test_ron_100_is_skipped_rather_than_filed_under_another_grade(): void
    {
        // The platform has no RON 100 fuel type. Mapping it onto RON 97 would
        // put one product's price under another, which reads as plausible and
        // is undetectable downstream.
        $this->platformStation();

        $doe = $this->doeStation(['company' => 'Petron', 'name' => 'Petron EDSA', 'city' => 'Quezon City']);
        $this->doePrice($doe, '2026-08-06', [
            'ron91' => null, 'ron95' => null, 'diesel' => null, 'ron100' => 70.00,
        ]);

        $this->artisan('fip:sync-doe-prices')->assertExitCode(1);

        $ron97 = FuelType::where('code', 'gasoline_ron97')->firstOrFail();

        $this->assertSame(
            0,
            StationPrice::where('fuel_type_id', $ron97->getKey())->count(),
        );
    }

    public function test_diesel_plus_maps_to_premium_diesel(): void
    {
        $this->platformStation();

        $doe = $this->doeStation(['company' => 'Petron', 'name' => 'Petron EDSA', 'city' => 'Quezon City']);
        $this->doePrice($doe, '2026-08-06', [
            'ron91' => null, 'ron95' => null, 'diesel' => null, 'diesel_plus' => 60.30,
        ]);

        $this->artisan('fip:sync-doe-prices')->assertExitCode(0);

        $premium = FuelType::where('code', 'diesel_premium')->firstOrFail();

        $this->assertEqualsWithDelta(
            60.30,
            (float) StationPrice::where('fuel_type_id', $premium->getKey())->firstOrFail()->price,
            0.001,
        );
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $this->platformStation();

        $doe = $this->doeStation(['company' => 'Petron', 'name' => 'Petron EDSA', 'city' => 'Quezon City']);
        $this->doePrice($doe, '2026-08-06', ['ron95' => 62.40]);

        $this->artisan('fip:sync-doe-prices', ['--dry-run' => true])->assertExitCode(0);

        $this->assertSame(0, StationPrice::count());
    }

    public function test_an_unmatched_station_does_not_cost_the_matched_ones(): void
    {
        $this->platformStation();

        $matched = $this->doeStation(['company' => 'Petron', 'name' => 'Petron EDSA', 'city' => 'Quezon City']);
        $orphan = $this->doeStation([
            'company' => 'Nowhere Fuels',
            'name' => 'Nowhere Fuels Atlantis',
            'city' => 'Atlantis',
            'barangay' => 'Deep',
        ]);

        $this->doePrice($matched, '2026-08-06', ['ron95' => 62.40]);
        $this->doePrice($orphan, '2026-08-06', ['ron95' => 61.00]);

        // One bad row in a national feed must not cost the other thousands.
        $this->artisan('fip:sync-doe-prices')->assertExitCode(0);

        $this->assertSame(1, StationPrice::where('fuel_type_id',
            FuelType::where('code', 'gasoline_ron95')->firstOrFail()->getKey())->count());
    }

    public function test_a_run_that_matched_nothing_fails(): void
    {
        // No exception is thrown, but a zero-write success would hide a broken
        // matcher until someone noticed the prices had stopped moving.
        $orphan = $this->doeStation([
            'company' => 'Nowhere Fuels',
            'name' => 'Nowhere Fuels Atlantis',
            'city' => 'Atlantis',
        ]);
        $this->doePrice($orphan, '2026-08-06', ['ron95' => 61.00]);

        $this->artisan('fip:sync-doe-prices')->assertExitCode(1);
    }

    public function test_it_fails_clearly_when_there_is_nothing_to_sync(): void
    {
        $this->artisan('fip:sync-doe-prices')
            ->expectsOutputToContain('holds no prices')
            ->assertExitCode(1);
    }

    public function test_a_specific_date_can_be_synced(): void
    {
        $this->platformStation();

        $doe = $this->doeStation(['company' => 'Petron', 'name' => 'Petron EDSA', 'city' => 'Quezon City']);
        $this->doePrice($doe, '2026-07-30', ['ron95' => 61.00]);
        $this->doePrice($doe, '2026-08-06', ['ron95' => 62.40]);

        $this->artisan('fip:sync-doe-prices', ['--date' => '2026-07-30'])->assertExitCode(0);

        $ron95 = FuelType::where('code', 'gasoline_ron95')->firstOrFail();

        $this->assertEqualsWithDelta(
            61.00,
            (float) StationPrice::where('fuel_type_id', $ron95->getKey())->firstOrFail()->price,
            0.001,
        );
    }

    public function test_the_brand_prefix_does_not_prevent_a_match(): void
    {
        // "Petron Shaw Boulevard" in the DOE feed and "Shaw Boulevard" in the
        // directory are the same site listed two ways.
        $brand = Brand::where('name', 'Petron')->firstOrFail();
        $city = City::where('name', 'Quezon City')->firstOrFail();

        GasStation::factory()->create([
            'brand_id' => $brand->getKey(),
            'city_id' => $city->getKey(),
            'name' => 'Shaw Boulevard',
        ]);

        $doe = $this->doeStation([
            'company' => 'Petron',
            'name' => 'Petron Shaw Boulevard',
            'city' => 'Quezon City',
        ]);
        $this->doePrice($doe, '2026-08-06', ['ron95' => 62.40]);

        $this->artisan('fip:sync-doe-prices')->assertExitCode(0);

        // Matched, so its prices landed on the directory's station rather than
        // being reported as an orphan.
        $ron95 = FuelType::where('code', 'gasoline_ron95')->firstOrFail();

        $this->assertEqualsWithDelta(
            62.40,
            (float) StationPrice::where('fuel_type_id', $ron95->getKey())->firstOrFail()->price,
            0.001,
        );
    }
}
