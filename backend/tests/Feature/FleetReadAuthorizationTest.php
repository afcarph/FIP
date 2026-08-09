<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ai\Models\FraudAlert;
use App\Domain\Fleet\Models\Driver;
use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserDevice;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Models\VehicleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who may read the fleet.
 *
 * Every endpoint here was previously reachable by any authenticated member of
 * the company, because tenant scoping answered "whose data" and nothing
 * answered "may you read it at all". Scoping is not authorisation: it narrows
 * a result set, it does not decide whether the caller should receive one.
 */
class FleetReadAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Vehicle $vehicle;

    private Vehicle $otherVehicle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        $this->company = Company::factory()->create();
        $this->vehicle = Vehicle::factory()->forCompany($this->company->id)->create();
        $this->otherVehicle = Vehicle::factory()->forCompany($this->company->id)->create();
    }

    private function alertFor(Vehicle $vehicle): FraudAlert
    {
        return FraudAlert::create([
            'company_id' => $vehicle->company_id,
            'vehicle_id' => $vehicle->getKey(),
            'alert_type' => 'fuel_loss',
            'severity' => 'high',
            'score' => 0.8,
            'status' => 'open',
            'detected_at' => now()->subHour(),
        ]);
    }

    private function driverAssignedToVehicle(): User
    {
        $user = $this->actingAsRole('driver', ['company_id' => $this->company->id]);

        $profile = Driver::create([
            'user_id' => $user->getKey(),
            'company_id' => $this->company->id,
            'first_name' => 'Pilot',
            'last_name' => 'Driver',
            'status' => 'active',
        ]);

        VehicleAssignment::create([
            'vehicle_id' => $this->vehicle->getKey(),
            'driver_id' => $profile->getKey(),
            'assigned_at' => now()->subDay(),
        ]);

        return $user;
    }

    /*
     * A note on /fleet/dashboard: the refusal path is asserted, the success
     * path is not. DashboardService::forFleet reaches
     * FuelExpenseService::monthlySeries, which uses MySQL's DATE_FORMAT, and
     * the suite runs on SQLite. The permission gate runs before that query, so
     * a 403 is still meaningful; a 200 cannot be reached here at all. This is
     * pre-existing and untouched by the gating work.
     */

    // ------------------------------------------------ driver: refused --------

    public function test_a_driver_cannot_read_company_wide_fleet_endpoints(): void
    {
        $this->driverAssignedToVehicle();

        $this->getJson('/api/v1/fleet')->assertStatus(403);
        $this->getJson('/api/v1/fleet/dashboard')->assertStatus(403);
        $this->getJson('/api/v1/fleet/drivers')->assertStatus(403);
        $this->getJson('/api/v1/fleet/fraud-alerts')->assertStatus(403);
    }

    public function test_a_driver_cannot_read_fleet_locations(): void
    {
        // Unchanged by this work — asserted so a later refactor cannot quietly
        // open it.
        $this->driverAssignedToVehicle();

        $this->getJson('/api/v1/fleet/locations')->assertStatus(403);
        $this->getJson("/api/v1/fleet/vehicles/{$this->vehicle->getKey()}/locations")
            ->assertStatus(403);
    }

    // ------------------------------------------------ driver: permitted ------

    public function test_a_driver_reads_alerts_for_their_own_vehicle(): void
    {
        $this->driverAssignedToVehicle();
        $this->alertFor($this->vehicle);

        $response = $this->getJson("/api/v1/vehicles/{$this->vehicle->getKey()}/alerts");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_a_driver_cannot_read_alerts_for_a_vehicle_they_are_not_assigned(): void
    {
        // The enumeration case: without this the per-vehicle endpoint would be
        // company-wide access one id at a time.
        $this->driverAssignedToVehicle();
        $this->alertFor($this->otherVehicle);

        $this->getJson("/api/v1/vehicles/{$this->otherVehicle->getKey()}/alerts")
            ->assertStatus(403);
    }

    public function test_a_driver_cannot_read_another_companys_vehicle_alerts(): void
    {
        $this->driverAssignedToVehicle();
        $foreign = Vehicle::factory()->forCompany(Company::factory()->create()->id)->create();
        $this->alertFor($foreign);

        $this->getJson("/api/v1/vehicles/{$foreign->getKey()}/alerts")->assertStatus(403);
    }

    public function test_a_driver_reads_the_latest_position_of_their_own_vehicle(): void
    {
        // The defect this closes: /vehicles carries no position and
        // /fleet/locations is refused, so a driver's own vehicle read Offline
        // while the server held a fix their own phone had just sent.
        $driver = $this->driverAssignedToVehicle();

        $device = UserDevice::create([
            'user_id' => $driver->getKey(),
            'vehicle_id' => $this->vehicle->getKey(),
            'device_uuid' => 'driver-phone',
            'platform' => 'ios',
        ]);

        // The cached position is written by the ingest service, not mass
        // assigned, so the test sets it the same way the service does.
        $device->forceFill([
            'last_latitude' => 14.5995,
            'last_longitude' => 120.9842,
            'last_location_at' => now()->subMinutes(2),
        ])->save();

        $response = $this->getJson("/api/v1/vehicles/{$this->vehicle->getKey()}/location");

        $response->assertStatus(200);
        $this->assertSame(14.5995, $response->json('data.latitude'));
        $this->assertSame($this->vehicle->getKey(), $response->json('data.vehicle_id'));
        $this->assertNotNull($device->fresh());
    }

    public function test_a_driver_cannot_read_the_position_of_a_vehicle_they_are_not_assigned(): void
    {
        // Otherwise the per-vehicle endpoint is fleet-wide tracking one id at
        // a time.
        $this->driverAssignedToVehicle();

        $this->getJson("/api/v1/vehicles/{$this->otherVehicle->getKey()}/location")
            ->assertStatus(403);
    }

    public function test_a_driver_cannot_read_another_companys_vehicle_position(): void
    {
        $this->driverAssignedToVehicle();
        $foreign = Vehicle::factory()->forCompany(Company::factory()->create()->id)->create();

        $this->getJson("/api/v1/vehicles/{$foreign->getKey()}/location")->assertStatus(403);
    }

    public function test_a_vehicle_with_no_device_reports_no_position_rather_than_failing(): void
    {
        $this->driverAssignedToVehicle();

        $response = $this->getJson("/api/v1/vehicles/{$this->vehicle->getKey()}/location");

        $response->assertStatus(200);
        $this->assertNull($response->json('data'));
    }

    public function test_a_viewer_cannot_read_a_vehicle_position(): void
    {
        // Viewer holds no devices permission and is assigned to nothing.
        $this->actingAsRole('viewer', ['company_id' => $this->company->id]);

        $this->getJson("/api/v1/vehicles/{$this->vehicle->getKey()}/location")
            ->assertStatus(403);
    }

    // ------------------------------------------------ fleet manager ----------

    public function test_a_fleet_manager_retains_every_fleet_read(): void
    {
        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $this->getJson('/api/v1/fleet')->assertStatus(200);
        $this->getJson('/api/v1/fleet/drivers')->assertStatus(200);
        $this->getJson('/api/v1/fleet/fraud-alerts')->assertStatus(200);
        $this->getJson('/api/v1/fleet/locations')->assertStatus(200);
    }

    public function test_a_fleet_manager_reads_alerts_for_any_vehicle_in_their_tenant(): void
    {
        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);
        $this->alertFor($this->otherVehicle);

        // Holds fraud.view, so no assignment is needed.
        $this->getJson("/api/v1/vehicles/{$this->otherVehicle->getKey()}/alerts")
            ->assertStatus(200);
    }

    public function test_a_fleet_manager_cannot_read_another_companys_alerts(): void
    {
        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);
        $foreign = Vehicle::factory()->forCompany(Company::factory()->create()->id)->create();

        $this->getJson("/api/v1/vehicles/{$foreign->getKey()}/alerts")->assertStatus(403);
    }

    // ------------------------------------------------ company manager --------

    public function test_a_company_manager_retains_their_fleet_reads(): void
    {
        $this->actingAsRole('company_manager', ['company_id' => $this->company->id]);

        $this->getJson('/api/v1/fleet')->assertStatus(200);
        $this->getJson('/api/v1/fleet/drivers')->assertStatus(200);
        $this->getJson('/api/v1/fleet/fraud-alerts')->assertStatus(200);
    }

    public function test_a_company_manager_cannot_read_location_history(): void
    {
        // Holds devices.location.view but not .history — a privacy boundary,
        // asserted here so gating work cannot erode it.
        $this->actingAsRole('company_manager', ['company_id' => $this->company->id]);

        $this->getJson('/api/v1/fleet/locations')->assertStatus(200);
        $this->getJson("/api/v1/fleet/vehicles/{$this->vehicle->getKey()}/locations")
            ->assertStatus(403);
    }

    // ------------------------------------------------ platform admin ---------

    public function test_a_super_admin_reads_everything(): void
    {
        $this->actingAsRole('super_admin', ['company_id' => $this->company->id]);

        $this->getJson('/api/v1/fleet')->assertStatus(200);
        $this->getJson('/api/v1/fleet/drivers')->assertStatus(200);
        $this->getJson('/api/v1/fleet/fraud-alerts')->assertStatus(200);
        $this->getJson('/api/v1/fleet/locations')->assertStatus(200);
        $this->getJson("/api/v1/vehicles/{$this->vehicle->getKey()}/alerts")->assertStatus(200);
    }

    // ------------------------------------------------ unauthenticated --------

    public function test_no_fleet_read_is_reachable_without_a_session(): void
    {
        foreach ([
            '/api/v1/fleet',
            '/api/v1/fleet/dashboard',
            '/api/v1/fleet/drivers',
            '/api/v1/fleet/fraud-alerts',
            '/api/v1/fleet/locations',
            "/api/v1/vehicles/{$this->vehicle->getKey()}/alerts",
        ] as $path) {
            $this->getJson($path)->assertStatus(401);
        }
    }
}
