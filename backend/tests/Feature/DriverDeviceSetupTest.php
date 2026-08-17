<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Fleet\Models\DeviceLocation;
use App\Domain\Fleet\Models\Driver;
use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserDevice;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Models\VehicleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The driver's own setup path: register this phone, attach it to the vehicle
 * they are actually assigned to, and start reporting.
 *
 * The point of these tests is that a driver can do all of it *without* being
 * given anything extra. If one of them starts needing a new permission, the
 * flow has drifted away from what a driver is supposed to be able to do.
 */
class DriverDeviceSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    private Vehicle $assigned;

    private Vehicle $unassigned;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        $company = Company::factory()->create();
        $this->driver = $this->actingAsRole('driver', ['company_id' => $company->id]);

        $this->assigned = Vehicle::factory()->forCompany($company->id)->create(['plate_number' => 'NS 1001']);
        $this->unassigned = Vehicle::factory()->forCompany($company->id)->create(['plate_number' => 'NS 1002']);

        // Created directly rather than via a factory: none exists, and adding
        // one for a single test would be more surface than the test needs.
        $profile = Driver::create([
            'user_id' => $this->driver->getKey(),
            'company_id' => $company->id,
            'first_name' => 'Pilot',
            'last_name' => 'Driver',
            'status' => 'active',
        ]);

        VehicleAssignment::create([
            'vehicle_id' => $this->assigned->getKey(),
            'driver_id' => $profile->getKey(),
            'assigned_at' => now()->subDay(),
        ]);
    }

    // -------------------------------------------------------- registration ---

    public function test_a_driver_can_register_their_own_device(): void
    {
        $response = $this->postJson('/api/v1/devices', [
            'device_uuid' => 'pilot-iphone',
            'platform' => 'ios',
            'device_name' => 'Pilot iPhone',
        ]);

        // 201: registration creates a resource.
        $this->assertApiSuccess($response, 201);
        $this->assertDatabaseHas('user_devices', [
            'user_id' => $this->driver->getKey(),
            'device_uuid' => 'pilot-iphone',
        ]);
    }

    public function test_registration_binds_the_device_to_the_authenticated_user(): void
    {
        // The body carries no user id, and could not be trusted if it did.
        $this->postJson('/api/v1/devices', ['device_uuid' => 'pilot-iphone', 'platform' => 'ios']);

        $this->assertSame(
            $this->driver->getKey(),
            UserDevice::where('device_uuid', 'pilot-iphone')->first()?->user_id,
        );
    }

    public function test_registering_the_same_installation_twice_does_not_duplicate(): void
    {
        $this->postJson('/api/v1/devices', ['device_uuid' => 'pilot-iphone', 'platform' => 'ios']);
        $this->postJson('/api/v1/devices', ['device_uuid' => 'pilot-iphone', 'platform' => 'ios']);

        $this->assertSame(1, UserDevice::where('device_uuid', 'pilot-iphone')->count());
    }

    // ---------------------------------------------------------- assignment ---

    public function test_a_driver_can_attach_their_device_to_the_vehicle_they_are_assigned(): void
    {
        $device = $this->registerDevice();

        $response = $this->patchJson("/api/v1/devices/{$device->getKey()}", [
            'vehicle_id' => $this->assigned->getKey(),
        ]);

        $this->assertApiSuccess($response);
        $this->assertTrue($response->json('data.can_report_location'));
        $this->assertSame($this->assigned->getKey(), $device->fresh()->vehicle_id);
    }

    public function test_the_assigned_vehicle_is_discoverable_from_the_vehicles_endpoint(): void
    {
        // The client identifies "my vehicle" by matching the signed-in user
        // against assigned_driver.user_id, so that field has to be present.
        $response = $this->getJson('/api/v1/vehicles?per_page=50');

        $mine = collect($response->json('data'))
            ->firstWhere('assigned_driver.user_id', $this->driver->getKey());

        $this->assertNotNull($mine, 'The driver must be able to find their own assignment.');
        $this->assertSame('NS 1001', $mine['plate_number']);
    }

    public function test_a_driver_without_an_assignment_finds_no_vehicle_of_their_own(): void
    {
        VehicleAssignment::query()->delete();

        $response = $this->getJson('/api/v1/vehicles?per_page=50');

        $mine = collect($response->json('data'))
            ->firstWhere('assigned_driver.user_id', $this->driver->getKey());

        $this->assertNull($mine, 'With no assignment the app must show an empty state, not guess.');
    }

    public function test_a_driver_cannot_attach_their_device_to_another_companys_vehicle(): void
    {
        $foreign = Vehicle::factory()->create(['owner_id' => User::factory()->create()->getKey()]);
        $device = $this->registerDevice();

        $this->patchJson("/api/v1/devices/{$device->getKey()}", ['vehicle_id' => $foreign->getKey()])
            ->assertStatus(403);

        $this->assertNull($device->fresh()->vehicle_id);
    }

    public function test_a_driver_cannot_attach_a_device_they_do_not_own(): void
    {
        $stranger = User::factory()->create();
        $theirs = UserDevice::create([
            'user_id' => $stranger->getKey(),
            'device_uuid' => 'stranger-phone',
            'platform' => 'android',
        ]);

        $this->patchJson("/api/v1/devices/{$theirs->getKey()}", [
            'vehicle_id' => $this->assigned->getKey(),
        ])->assertStatus(403);
    }

    // ------------------------------------------------------------ reporting ---

    public function test_location_upload_succeeds_once_the_device_is_set_up(): void
    {
        $device = $this->registerDevice();
        $this->patchJson("/api/v1/devices/{$device->getKey()}", [
            'vehicle_id' => $this->assigned->getKey(),
        ]);

        $response = $this->withHeaders(['X-Device-Id' => 'pilot-iphone'])->postJson(
            '/api/v1/devices/location',
            ['points' => [[
                'latitude' => 14.5995,
                'longitude' => 120.9842,
                'accuracy_m' => 8.0,
                'recorded_at' => now()->subMinute()->toIso8601String(),
            ]]],
        );

        $this->assertApiSuccess($response);
        $this->assertSame(1, $response->json('data.accepted'));
        $this->assertSame($this->assigned->getKey(), DeviceLocation::first()?->vehicle_id);
    }

    public function test_a_registered_but_unassigned_device_cannot_report(): void
    {
        $this->registerDevice();

        $this->assertApiError(
            $this->withHeaders(['X-Device-Id' => 'pilot-iphone'])->postJson(
                '/api/v1/devices/location',
                ['points' => [[
                    'latitude' => 14.5995,
                    'longitude' => 120.9842,
                    'recorded_at' => now()->subMinute()->toIso8601String(),
                ]]],
            ),
            'device_unassigned',
            403,
        );
    }

    // ------------------------------------------------------------- boundary ---

    public function test_the_driver_gains_no_fleet_wide_access_from_this_flow(): void
    {
        $device = $this->registerDevice();
        $this->patchJson("/api/v1/devices/{$device->getKey()}", [
            'vehicle_id' => $this->assigned->getKey(),
        ]);

        // Setting up a device must not become a back door to the fleet.
        $this->getJson('/api/v1/fleet/locations')->assertStatus(403);
        $this->getJson("/api/v1/fleet/vehicles/{$this->assigned->getKey()}/locations")->assertStatus(403);
        $this->getJson('/api/v1/admin/settings/privacy')->assertStatus(403);
    }

    public function test_the_driver_still_holds_only_their_original_permissions(): void
    {
        $this->assertTrue($this->driver->can('devices.view'));
        $this->assertTrue($this->driver->can('devices.manage'));
        $this->assertFalse($this->driver->can('devices.location.view'));
        $this->assertFalse($this->driver->can('devices.location.history'));
        $this->assertFalse($this->driver->can('settings.manage'));
    }

    private function registerDevice(): UserDevice
    {
        $this->postJson('/api/v1/devices', ['device_uuid' => 'pilot-iphone', 'platform' => 'ios']);

        return UserDevice::where('device_uuid', 'pilot-iphone')->firstOrFail();
    }
}
