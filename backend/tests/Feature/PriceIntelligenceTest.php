<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pricing\Models\FuelType;
use App\Domain\Pricing\Models\StationPrice;
use App\Domain\Station\Models\City;
use App\Domain\Station\Models\GasStation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PriceIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    public function test_the_station_directory_is_public(): void
    {
        GasStation::factory()->count(3)->create();

        $this->assertApiSuccess($this->getJson('/api/v1/stations'));
    }

    public function test_proximity_search_returns_distance_and_orders_by_it(): void
    {
        $city = City::where('code', 'MKT')->firstOrFail();

        $near = GasStation::factory()->create(['city_id' => $city->id, 'latitude' => 14.5550, 'longitude' => 121.0245]);
        $far = GasStation::factory()->create(['city_id' => $city->id, 'latitude' => 14.6760, 'longitude' => 121.0437]);

        $response = $this->getJson('/api/v1/stations/nearby?latitude=14.5547&longitude=121.0244&radius_km=5');

        $this->assertApiSuccess($response);

        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($near->id, $ids);
        $this->assertNotContains($far->id, $ids, 'A station 14 km away must fall outside a 5 km radius.');
        $this->assertNotNull($response->json('data.0.distance_km'));
    }

    public function test_proximity_search_validates_its_coordinates(): void
    {
        $response = $this->getJson('/api/v1/stations/nearby?latitude=999&longitude=121.02');

        $this->assertApiValidationErrors($response, 'latitude');
    }

    public function test_the_radius_is_capped(): void
    {
        $max = (float) config('fip.pricing.max_radius_km');

        $response = $this->getJson('/api/v1/stations/nearby?latitude=14.55&longitude=121.02&radius_km='.($max + 100));

        $this->assertApiError($response, 'validation_failed', 422);
    }

    public function test_a_station_operator_can_update_their_own_board_prices(): void
    {
        $user = $this->actingAsRole('station_admin');
        $station = GasStation::factory()->create(['managed_by' => $user->id]);
        $fuelType = FuelType::where('code', 'diesel')->firstOrFail();

        $response = $this->putJson("/api/v1/stations/{$station->id}/prices", [
            'prices' => [['fuel_type_id' => $fuelType->id, 'price' => 57.90]],
        ]);

        $this->assertApiSuccess($response);
        $this->assertDatabaseHas('station_prices', [
            'station_id' => $station->id,
            'fuel_type_id' => $fuelType->id,
            'price' => 57.9000,
            'source' => 'operator',
        ]);
    }

    public function test_an_operator_cannot_update_a_station_they_do_not_manage(): void
    {
        $this->actingAsRole('station_admin');
        $foreign = GasStation::factory()->create();
        $fuelType = FuelType::first();

        $this->putJson("/api/v1/stations/{$foreign->id}/prices", [
            'prices' => [['fuel_type_id' => $fuelType->id, 'price' => 1.00]],
        ])->assertForbidden();
    }

    public function test_the_same_fuel_type_cannot_appear_twice_in_one_update(): void
    {
        $user = $this->actingAsRole('station_admin');
        $station = GasStation::factory()->create(['managed_by' => $user->id]);
        $fuelType = FuelType::first();

        $response = $this->putJson("/api/v1/stations/{$station->id}/prices", [
            'prices' => [
                ['fuel_type_id' => $fuelType->id, 'price' => 57.90],
                ['fuel_type_id' => $fuelType->id, 'price' => 58.90],
            ],
        ]);

        $this->assertApiError($response, 'validation_failed', 422);
    }

    public function test_the_comparison_matrix_reports_the_spread(): void
    {
        $fuelType = FuelType::where('code', 'diesel')->firstOrFail();

        foreach ([56.00, 58.00, 60.00] as $price) {
            StationPrice::factory()->create([
                'station_id' => GasStation::factory()->create()->id,
                'fuel_type_id' => $fuelType->id,
                'price' => $price,
            ]);
        }

        $response = $this->getJson('/api/v1/prices/comparison');

        $this->assertApiSuccess($response);

        $row = collect($response->json('data'))->firstWhere('fuel_code', 'diesel');

        $this->assertSame(56.0, $row['min_price']);
        $this->assertSame(60.0, $row['max_price']);
        $this->assertSame(4.0, $row['spread']);
    }
}
