<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Pricing\Models\FuelType;
use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tenant isolation is the highest-consequence rule in the platform: a leak
 * here exposes one company's fleet costs to another.
 */
class VehicleAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    public function test_a_user_sees_only_their_own_vehicles(): void
    {
        $user = $this->actingAsRole('user');

        Vehicle::factory()->create(['owner_id' => $user->id, 'plate_number' => 'MINE 001']);
        Vehicle::factory()->create(['owner_id' => User::factory()->create()->id, 'plate_number' => 'THRS 001']);

        $response = $this->getJson('/api/v1/vehicles');

        $this->assertApiSuccess($response);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('MINE 001', $response->json('data.0.plate_number'));
    }

    public function test_a_fleet_manager_cannot_read_another_companys_vehicle(): void
    {
        $otherCompany = Company::factory()->create();
        $foreignVehicle = Vehicle::factory()->create(['company_id' => $otherCompany->id]);

        $this->actingAsRole('fleet_manager', ['company_id' => Company::factory()->create()->id]);

        $this->getJson("/api/v1/vehicles/{$foreignVehicle->id}")->assertForbidden();
    }

    public function test_a_fleet_manager_can_read_their_own_companys_vehicle(): void
    {
        $company = Company::factory()->create();
        $vehicle = Vehicle::factory()->create(['company_id' => $company->id]);

        $this->actingAsRole('fleet_manager', ['company_id' => $company->id]);

        $this->assertApiSuccess($this->getJson("/api/v1/vehicles/{$vehicle->id}"));
    }

    public function test_a_driver_cannot_delete_a_company_vehicle(): void
    {
        $company = Company::factory()->create();
        $vehicle = Vehicle::factory()->create(['company_id' => $company->id]);

        $this->actingAsRole('driver', ['company_id' => $company->id]);

        $this->deleteJson("/api/v1/vehicles/{$vehicle->id}")->assertForbidden();
        $this->assertDatabaseHas('vehicles', ['id' => $vehicle->id, 'deleted_at' => null]);
    }

    public function test_a_super_administrator_sees_everything(): void
    {
        Vehicle::factory()->count(3)->create();

        $this->actingAsRole('super_admin');

        $response = $this->getJson('/api/v1/vehicles');

        $this->assertApiSuccess($response);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_a_duplicate_plate_number_is_rejected(): void
    {
        $user = $this->actingAsRole('user');
        Vehicle::factory()->create(['owner_id' => $user->id, 'plate_number' => 'ABC 1234']);

        $response = $this->postJson('/api/v1/vehicles', [
            'plate_number' => 'ABC 1234',
            'vehicle_type' => 'car',
            'fuel_type_id' => FuelType::first()->id,
        ]);

        $this->assertApiValidationErrors($response, 'plate_number');
    }
}
