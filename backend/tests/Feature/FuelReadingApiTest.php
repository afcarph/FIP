<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Fleet\Models\Driver;
use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Models\VehicleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FuelReadingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    // ---------------------------------------------------------- recording ---

    public function test_an_owner_can_record_a_fuel_reading(): void
    {
        $user = $this->actingAsRole('user');
        $vehicle = Vehicle::factory()->create(['owner_id' => $user->id, 'tank_capacity' => 50.0]);

        $response = $this->postJson("/api/v1/vehicles/{$vehicle->id}/fuel-readings", ['fuel_pct' => 64]);

        $this->assertApiSuccess($response, 201);
        $this->assertSame(64.0, $response->json('data.fuel_pct'));
        $this->assertSame(32.0, $response->json('data.fuel_litres'));
        $this->assertSame('manual', $response->json('data.source'));
        $this->assertSame('NORMAL', $response->json('data.vehicle.fuel_status'));

        $this->assertDatabaseHas('vehicle_fuel_readings', [
            'vehicle_id' => $vehicle->id,
            'fuel_pct' => 64.0,
            'recorded_by' => $user->id,
        ]);
    }

    public function test_recording_updates_the_vehicle_current_state(): void
    {
        $user = $this->actingAsRole('user');
        $vehicle = Vehicle::factory()->create(['owner_id' => $user->id, 'tank_capacity' => 50.0]);

        $this->postJson("/api/v1/vehicles/{$vehicle->id}/fuel-readings", ['fuel_pct' => 8]);

        $fresh = $vehicle->fresh();

        $this->assertSame(8.0, $fresh->current_fuel_pct);
        $this->assertNotNull($fresh->fuel_level_at);
    }

    public function test_a_second_reading_reports_the_delta(): void
    {
        $user = $this->actingAsRole('user');
        $vehicle = Vehicle::factory()->create(['owner_id' => $user->id]);

        $this->postJson("/api/v1/vehicles/{$vehicle->id}/fuel-readings", [
            'fuel_pct' => 80, 'recorded_at' => now()->subHour()->toDateTimeString(),
        ]);

        $response = $this->postJson("/api/v1/vehicles/{$vehicle->id}/fuel-readings", ['fuel_pct' => 55]);

        $this->assertSame(-25.0, $response->json('data.delta_pct'));
    }

    public function test_an_assigned_driver_may_record_for_a_company_vehicle(): void
    {
        $company = Company::factory()->create();
        $user = $this->actingAsRole('driver', ['company_id' => $company->id]);

        $vehicle = Vehicle::factory()->forCompany($company->id)->create();
        $driver = Driver::create([
            'user_id' => $user->id,
            'company_id' => $company->id,
            'first_name' => 'Jomar',
            'last_name' => 'Dela Cruz',
            'status' => 'active',
        ]);

        VehicleAssignment::create([
            'vehicle_id' => $vehicle->id,
            'driver_id' => $driver->id,
            'assigned_at' => now()->subDay(),
        ]);

        $response = $this->postJson("/api/v1/vehicles/{$vehicle->id}/fuel-readings", ['fuel_pct' => 42]);

        $this->assertApiSuccess($response, 201);
    }

    // ------------------------------------------------------ authorisation ---

    public function test_a_stranger_cannot_record_against_someone_elses_vehicle(): void
    {
        $this->actingAsRole('user');
        $vehicle = Vehicle::factory()->create(['owner_id' => User::factory()->create()->id]);

        $this->postJson("/api/v1/vehicles/{$vehicle->id}/fuel-readings", ['fuel_pct' => 50])
            ->assertStatus(403);

        $this->assertDatabaseCount('vehicle_fuel_readings', 0);
    }

    public function test_an_unassigned_driver_cannot_record_for_a_company_vehicle(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole('driver', ['company_id' => $company->id]);

        $vehicle = Vehicle::factory()->forCompany($company->id)->create();

        $this->postJson("/api/v1/vehicles/{$vehicle->id}/fuel-readings", ['fuel_pct' => 50])
            ->assertStatus(403);
    }

    public function test_the_endpoint_requires_authentication(): void
    {
        $vehicle = Vehicle::factory()->create();

        $this->postJson("/api/v1/vehicles/{$vehicle->id}/fuel-readings", ['fuel_pct' => 50])
            ->assertStatus(401);
    }

    // --------------------------------------------------------- validation ---

    public function test_it_rejects_a_level_outside_zero_to_one_hundred(): void
    {
        $user = $this->actingAsRole('user');
        $vehicle = Vehicle::factory()->create(['owner_id' => $user->id]);

        $this->assertApiValidationErrors(
            $this->postJson("/api/v1/vehicles/{$vehicle->id}/fuel-readings", ['fuel_pct' => 140]),
            'fuel_pct',
        );
    }

    public function test_it_rejects_a_reading_dated_in_the_future(): void
    {
        $user = $this->actingAsRole('user');
        $vehicle = Vehicle::factory()->create(['owner_id' => $user->id]);

        $this->assertApiValidationErrors(
            $this->postJson("/api/v1/vehicles/{$vehicle->id}/fuel-readings", [
                'fuel_pct' => 50, 'recorded_at' => now()->addDay()->toDateTimeString(),
            ]),
            'recorded_at',
        );
    }

    /**
     * Simulated rows are the ones `--purge` deletes. If a client could mint
     * them, a real reading could be filed under a source that later gets wiped.
     */
    public function test_a_client_cannot_mint_a_simulated_reading(): void
    {
        $user = $this->actingAsRole('user');
        $vehicle = Vehicle::factory()->create(['owner_id' => $user->id]);

        $this->assertApiValidationErrors(
            $this->postJson("/api/v1/vehicles/{$vehicle->id}/fuel-readings", [
                'fuel_pct' => 50, 'source' => 'simulated',
            ]),
            'source',
        );
    }

    public function test_a_replayed_reading_does_not_duplicate_the_series(): void
    {
        $user = $this->actingAsRole('user');
        $vehicle = Vehicle::factory()->create(['owner_id' => $user->id]);
        $at = now()->subMinutes(5)->toDateTimeString();

        $this->postJson("/api/v1/vehicles/{$vehicle->id}/fuel-readings", ['fuel_pct' => 50, 'recorded_at' => $at]);
        $this->postJson("/api/v1/vehicles/{$vehicle->id}/fuel-readings", ['fuel_pct' => 50, 'recorded_at' => $at])
            ->assertStatus(201);

        $this->assertDatabaseCount('vehicle_fuel_readings', 1);
    }

    // ------------------------------------------------- VehicleResource ---

    public function test_the_vehicle_resource_exposes_the_fuel_block(): void
    {
        config(['fip.fuel_level.low_pct' => 25.0, 'fip.fuel_level.critical_pct' => 10.0]);

        $user = $this->actingAsRole('user');
        $vehicle = Vehicle::factory()->create(['owner_id' => $user->id, 'tank_capacity' => 50.0]);

        $this->postJson("/api/v1/vehicles/{$vehicle->id}/fuel-readings", ['fuel_pct' => 9]);

        $response = $this->getJson('/api/v1/vehicles');

        $this->assertApiSuccess($response);
        $this->assertSame(9.0, $response->json('data.0.fuel.current_percentage'));
        $this->assertSame(4.5, $response->json('data.0.fuel.current_litres'));
        $this->assertSame('CRITICAL', $response->json('data.0.fuel.status'));
        $this->assertNotNull($response->json('data.0.fuel.recorded_at'));
        $this->assertFalse($response->json('data.0.fuel.is_stale'));
    }

    public function test_a_vehicle_with_no_reading_reports_a_null_fuel_status(): void
    {
        $user = $this->actingAsRole('user');
        Vehicle::factory()->create(['owner_id' => $user->id]);

        $response = $this->getJson('/api/v1/vehicles');

        $this->assertApiSuccess($response);
        $this->assertNull($response->json('data.0.fuel.current_percentage'));
        $this->assertNull(
            $response->json('data.0.fuel.status'),
            'No reading must not render as a reassuring NORMAL.',
        );
    }

    public function test_an_old_reading_is_flagged_stale(): void
    {
        config(['fip.fuel_level.stale_after_minutes' => 60]);

        $user = $this->actingAsRole('user');
        $vehicle = Vehicle::factory()->create(['owner_id' => $user->id]);

        $this->postJson("/api/v1/vehicles/{$vehicle->id}/fuel-readings", [
            'fuel_pct' => 70, 'recorded_at' => now()->subHours(6)->toDateTimeString(),
        ]);

        $response = $this->getJson('/api/v1/vehicles');

        $this->assertTrue($response->json('data.0.fuel.is_stale'));
    }
}
