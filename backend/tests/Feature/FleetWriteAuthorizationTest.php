<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ai\Models\FraudAlert;
use App\Domain\Fleet\Models\Driver;
use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Models\VehicleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who may write to the fleet.
 *
 * Both endpoints covered here were reachable by a driver: assignment because
 * it guarded a management action with a vehicle-edit check that an assigned
 * driver legitimately passes, and alert resolution because it checked company
 * membership and never the capability. Neither was a data leak; both let the
 * wrong person change something.
 *
 * The negative cases matter more than the positive ones — a driver being able
 * to reassign their own vehicle, or close the alert raised against them, is
 * the failure this file exists to prevent recurring.
 */
class FleetWriteAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Vehicle $vehicle;

    private Driver $driverProfile;

    private Driver $otherDriver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        $this->company = Company::factory()->create();
        $this->vehicle = Vehicle::factory()->forCompany($this->company->id)->create();

        $this->driverProfile = $this->makeDriver('Pilot', 'Driver');
        $this->otherDriver = $this->makeDriver('Second', 'Driver');
    }

    private function makeDriver(string $first, string $last, ?int $userId = null): Driver
    {
        return Driver::create([
            'user_id' => $userId,
            'company_id' => $this->company->id,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'active',
        ]);
    }

    private function assignTo(Driver $driver): VehicleAssignment
    {
        return VehicleAssignment::create([
            'vehicle_id' => $this->vehicle->getKey(),
            'driver_id' => $driver->getKey(),
            'assigned_at' => now()->subDay(),
        ]);
    }

    /** @return array<string, mixed> */
    private function assignmentPayload(?Driver $driver = null): array
    {
        return [
            'vehicle_id' => $this->vehicle->getKey(),
            'driver_id' => ($driver ?? $this->otherDriver)->getKey(),
        ];
    }

    private function openAlert(): FraudAlert
    {
        return FraudAlert::create([
            'company_id' => $this->company->id,
            'vehicle_id' => $this->vehicle->getKey(),
            'alert_type' => 'fuel_loss',
            'severity' => 'high',
            'score' => 0.8,
            'status' => 'open',
            'detected_at' => now()->subHours(3),
        ]);
    }

    // ------------------------------------------------- driver: must not ------

    public function test_a_driver_cannot_assign_a_vehicle(): void
    {
        $user = $this->actingAsRole('driver', ['company_id' => $this->company->id]);
        $this->driverProfile->update(['user_id' => $user->getKey()]);

        $this->postJson('/api/v1/fleet/assignments', $this->assignmentPayload())->assertStatus(403);

        $this->assertDatabaseCount('vehicle_assignments', 0);
    }

    public function test_an_assigned_driver_cannot_reassign_their_own_vehicle(): void
    {
        // The exact escalation that was live: being assigned to a vehicle
        // passed VehiclePolicy::update, which was all the endpoint asked for.
        $user = $this->actingAsRole('driver', ['company_id' => $this->company->id]);
        $this->driverProfile->update(['user_id' => $user->getKey()]);
        $assignment = $this->assignTo($this->driverProfile);

        $this->postJson('/api/v1/fleet/assignments', $this->assignmentPayload())->assertStatus(403);

        $this->assertNull(
            $assignment->fresh()->released_at,
            'The original assignment must survive a refused reassignment.',
        );
        $this->assertSame(1, VehicleAssignment::count());
    }

    public function test_a_driver_cannot_resolve_a_fraud_alert(): void
    {
        $user = $this->actingAsRole('driver', ['company_id' => $this->company->id]);
        $this->driverProfile->update(['user_id' => $user->getKey()]);
        $alert = $this->openAlert();

        $this->patchJson("/api/v1/fleet/fraud-alerts/{$alert->getKey()}", ['status' => 'dismissed'])
            ->assertStatus(403);

        $this->assertSame('open', $alert->fresh()->status);
        $this->assertNull($alert->fresh()->resolved_by);
    }

    public function test_a_driver_cannot_dismiss_the_alert_raised_against_their_own_vehicle(): void
    {
        // The worst case: the subject of an alert closing it.
        $user = $this->actingAsRole('driver', ['company_id' => $this->company->id]);
        $this->driverProfile->update(['user_id' => $user->getKey()]);
        $this->assignTo($this->driverProfile);
        $alert = $this->openAlert();

        $this->patchJson("/api/v1/fleet/fraud-alerts/{$alert->getKey()}", ['status' => 'dismissed'])
            ->assertStatus(403);

        $this->assertSame('open', $alert->fresh()->status);
    }

    // ------------------------------------------------- driver: must still ----

    public function test_a_driver_keeps_the_functions_they_are_meant_to_have(): void
    {
        $user = $this->actingAsRole('driver', ['company_id' => $this->company->id]);
        $this->driverProfile->update(['user_id' => $user->getKey()]);
        $this->assignTo($this->driverProfile);

        // Reading their vehicle, registering a device, attaching it: untouched.
        $this->getJson('/api/v1/vehicles?per_page=50')->assertStatus(200);

        // Company-wide alerts are now gated on fraud.view, which a driver does
        // not hold; their own vehicle's alerts are read per-vehicle instead.
        $this->getJson('/api/v1/fleet/fraud-alerts')->assertStatus(403);
        $this->getJson("/api/v1/vehicles/{$this->vehicle->getKey()}/alerts")->assertStatus(200);

        $device = $this->postJson('/api/v1/devices', [
            'device_uuid' => 'driver-phone',
            'platform' => 'ios',
        ])->assertStatus(201);

        $this->patchJson("/api/v1/devices/{$device->json('data.id')}", [
            'vehicle_id' => $this->vehicle->getKey(),
        ])->assertStatus(200);
    }

    public function test_an_assigned_driver_can_still_update_their_vehicle(): void
    {
        // VehiclePolicy was deliberately left alone; this proves the fix did
        // not tighten it as a side effect.
        $user = $this->actingAsRole('driver', ['company_id' => $this->company->id]);
        $this->driverProfile->update(['user_id' => $user->getKey()]);
        $this->assignTo($this->driverProfile);

        $this->assertTrue($user->can('update', $this->vehicle));
    }

    // ------------------------------------------------- fleet manager ---------

    public function test_a_fleet_manager_can_still_assign_a_vehicle(): void
    {
        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $this->postJson('/api/v1/fleet/assignments', $this->assignmentPayload())
            ->assertStatus(201);

        $this->assertDatabaseCount('vehicle_assignments', 1);
    }

    public function test_a_fleet_manager_can_still_reassign_a_vehicle(): void
    {
        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);
        $first = $this->assignTo($this->driverProfile);

        $this->postJson('/api/v1/fleet/assignments', $this->assignmentPayload())
            ->assertStatus(201);

        $this->assertNotNull($first->fresh()->released_at, 'The previous assignment is released.');
    }

    public function test_a_fleet_manager_can_still_resolve_a_fraud_alert(): void
    {
        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);
        $alert = $this->openAlert();

        $this->patchJson("/api/v1/fleet/fraud-alerts/{$alert->getKey()}", ['status' => 'confirmed'])
            ->assertStatus(200);

        $this->assertSame('confirmed', $alert->fresh()->status);
    }

    // ------------------------------------------------- company manager -------

    public function test_a_company_manager_can_still_assign_a_vehicle(): void
    {
        // company_manager holds fleet.manage but not fleet.assign_drivers, so
        // the gate accepts either — removing this would strip a capability the
        // role legitimately had.
        $this->actingAsRole('company_manager', ['company_id' => $this->company->id]);

        $this->postJson('/api/v1/fleet/assignments', $this->assignmentPayload())
            ->assertStatus(201);
    }

    public function test_a_company_manager_cannot_resolve_a_fraud_alert(): void
    {
        // Deliberate: the role seed grants fraud.view and withholds
        // fraud.resolve. Enforcing that is the point of the fix.
        $this->actingAsRole('company_manager', ['company_id' => $this->company->id]);
        $alert = $this->openAlert();

        $this->patchJson("/api/v1/fleet/fraud-alerts/{$alert->getKey()}", ['status' => 'dismissed'])
            ->assertStatus(403);

        $this->assertSame('open', $alert->fresh()->status);
    }

    public function test_a_company_manager_can_still_read_alerts(): void
    {
        $this->actingAsRole('company_manager', ['company_id' => $this->company->id]);
        $this->openAlert();

        $this->getJson('/api/v1/fleet/fraud-alerts')->assertStatus(200);
    }

    // ------------------------------------------------- tenant boundary -------

    public function test_a_manager_cannot_assign_another_companys_vehicle(): void
    {
        // Capability alone is not enough; scoping still applies.
        $this->actingAsRole('fleet_manager', ['company_id' => Company::factory()->create()->id]);

        $this->postJson('/api/v1/fleet/assignments', $this->assignmentPayload())->assertStatus(403);
    }

    public function test_a_manager_cannot_resolve_another_companys_alert(): void
    {
        $this->actingAsRole('fleet_manager', ['company_id' => Company::factory()->create()->id]);
        $alert = $this->openAlert();

        $this->patchJson("/api/v1/fleet/fraud-alerts/{$alert->getKey()}", ['status' => 'dismissed'])
            ->assertStatus(403);
    }

    public function test_an_unauthenticated_request_cannot_write(): void
    {
        $alert = $this->openAlert();

        $this->postJson('/api/v1/fleet/assignments', $this->assignmentPayload())->assertStatus(401);
        $this->patchJson("/api/v1/fleet/fraud-alerts/{$alert->getKey()}", ['status' => 'dismissed'])
            ->assertStatus(401);
    }

    // ------------------------------------------------- permission model ------

    public function test_the_permission_model_itself_is_unchanged(): void
    {
        $driver = $this->actingAsRole('driver');
        $fleet = User::factory()->create();
        $fleet->assignRole('fleet_manager');
        $company = User::factory()->create();
        $company->assignRole('company_manager');

        // Nothing was granted or revoked to make the tests above pass.
        $this->assertFalse($driver->can('fleet.manage'));
        $this->assertFalse($driver->can('fleet.assign_drivers'));
        $this->assertFalse($driver->can('fraud.resolve'));

        $this->assertTrue($fleet->can('fleet.assign_drivers'));
        $this->assertTrue($fleet->can('fraud.resolve'));

        $this->assertTrue($company->can('fleet.manage'));
        $this->assertTrue($company->can('fraud.view'));
        $this->assertFalse($company->can('fraud.resolve'));
    }
}
