<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Fleet\Models\Driver;
use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserDevice;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Which handsets one driver has.
 *
 * This endpoint exists because the setup page asked device *health* whether a
 * driver had installed the app, and health only lists devices already attached
 * to a vehicle. So a driver who had a login, had installed the app and had
 * signed in read as "not installed" on the one page built to walk somebody
 * through installing it — while the onboarding checklist, which asks the
 * devices table directly, said the step was done.
 *
 * What these protect is the join: driver → linked user → devices, with no
 * vehicle anywhere in it, and no way to reach a user who is not this driver.
 */
class DriverDeviceTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Driver $driver;

    private User $driverUser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        $this->company = Company::factory()->create(['subscription_tier' => 'business']);
        $this->driver = Driver::create([
            'company_id' => $this->company->id,
            'first_name' => 'Pilot',
            'last_name' => 'Driver',
            'status' => 'active',
        ]);
    }

    /** Gives the driver a login, the way the invite flow does. */
    private function withAccount(): User
    {
        $this->driverUser = User::factory()->create(['company_id' => $this->company->id]);
        $this->driverUser->assignRole('driver');
        $this->driver->forceFill(['user_id' => $this->driverUser->getKey()])->save();

        return $this->driverUser;
    }

    private function device(array $overrides = []): UserDevice
    {
        // `$overrides +` and not `+ $overrides`: array union keeps the *left*
        // operand's keys, so writing it the other way round silently ignored
        // every override and quietly made the iOS case a second Android one.
        return UserDevice::create($overrides + [
            'user_id' => $this->driverUser->getKey(),
            'device_uuid' => 'handset-'.uniqid(),
            'platform' => 'android',
            'device_name' => 'Pilot Pixel',
        ]);
    }

    private function devices(?int $driverId = null): array
    {
        return $this->getJson('/api/v1/fleet/drivers/'.($driverId ?? $this->driver->getKey()).'/devices')
            ->assertStatus(200)
            ->json('data');
    }

    // ------------------------------------------- the exact production bug ---

    public function test_a_registered_handset_with_no_vehicle_is_still_reported(): void
    {
        /*
         * The regression. Every condition here is the state a real driver was
         * in: account created, signed in on the phone, device registered, and
         * `vehicle_id` still null because registration never sets one and
         * nobody had assigned them a truck yet.
         */
        $this->withAccount();
        $device = $this->device();

        $this->assertNull($device->vehicle_id, 'registration must not attach a vehicle');

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $devices = $this->devices();

        $this->assertCount(1, $devices);
        $this->assertSame('Pilot Pixel', $devices[0]['device_name']);
        $this->assertNull($devices[0]['vehicle']);
    }

    public function test_that_same_handset_is_invisible_to_fleet_health(): void
    {
        // The other half of the bug, pinned so nobody "fixes" the difference by
        // widening health: health is about vehicles and is right to exclude it.
        // The two endpoints answer different questions and must keep doing so.
        $this->withAccount();
        $this->device();

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $health = $this->getJson('/api/v1/fleet/devices')->assertStatus(200)->json('data');

        $this->assertCount(0, $health);
        $this->assertCount(1, $this->devices());
    }

    public function test_the_checklist_and_this_endpoint_agree_about_the_device_step(): void
    {
        // The disagreement itself, which is what was actually shipped broken.
        $this->withAccount();
        $this->device();

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $steps = collect($this->getJson('/api/v1/fleet/onboarding')->json('data.steps'));

        $this->assertTrue($steps->firstWhere('key', 'device')['done']);
        $this->assertNotEmpty($this->devices());
    }

    public function test_attaching_a_vehicle_later_does_not_change_the_answer(): void
    {
        // Assignment is a different axis. A connected phone stays connected.
        $this->withAccount();
        $device = $this->device();

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $this->assertCount(1, $this->devices());

        $vehicle = Vehicle::factory()->create(['company_id' => $this->company->id]);
        $device->forceFill(['vehicle_id' => $vehicle->getKey()])->save();

        $after = $this->devices();

        $this->assertCount(1, $after);
        $this->assertSame($vehicle->plate_number, $after[0]['vehicle']['plate_number']);

        // And now, unlike before, health sees it too.
        $this->assertCount(1, $this->getJson('/api/v1/fleet/devices')->json('data'));
    }

    // ---------------------------------------------------- the other states ---

    public function test_a_driver_with_no_login_has_no_devices(): void
    {
        // Not an error and not a 404: a driver without an account is the
        // ordinary state of a newly added driver.
        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $this->assertSame([], $this->devices());
    }

    public function test_a_driver_with_a_login_but_no_handset_has_no_devices(): void
    {
        $this->withAccount();

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $this->assertSame([], $this->devices());
    }

    public function test_an_iphone_counts(): void
    {
        $this->withAccount();
        $this->device(['platform' => 'ios', 'device_name' => 'Pilot iPhone']);

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $this->assertCount(1, $this->devices());
    }

    public function test_a_revoked_handset_does_not_count(): void
    {
        // Matches the checklist: a revoked device is not a device you have.
        $this->withAccount();
        $device = $this->device();
        $device->forceFill(['revoked_at' => now()])->save();

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $this->assertSame([], $this->devices());
    }

    public function test_a_browser_registration_does_not_count(): void
    {
        // Signing in on the web creates a device row. It can never ride in a
        // vehicle, and treating it as a handset is what made the drivers page
        // claim installations that had not happened.
        $this->withAccount();
        $this->device(['platform' => 'web', 'device_name' => 'Chrome on macOS']);

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $this->assertSame([], $this->devices());
    }

    public function test_a_driver_with_two_handsets_gets_both(): void
    {
        $this->withAccount();
        $this->device(['device_name' => 'Old phone']);
        $this->device(['platform' => 'ios', 'device_name' => 'New phone']);

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $this->assertCount(2, $this->devices());
    }

    // -------------------------------------------------------- who may ask ---

    public function test_a_company_manager_may_ask(): void
    {
        $this->withAccount();
        $this->device();

        $this->actingAsRole('company_manager', ['company_id' => $this->company->id]);

        $this->assertCount(1, $this->devices());
    }

    public function test_a_viewer_may_not(): void
    {
        // A viewer is not given the driver roster, so they are not given a
        // driver's handsets either.
        $this->withAccount();

        $this->actingAsRole('viewer', ['company_id' => $this->company->id]);

        $this->getJson('/api/v1/fleet/drivers/'.$this->driver->getKey().'/devices')
            ->assertStatus(403);
    }

    public function test_a_driver_may_not_read_a_roster_colleague(): void
    {
        $this->withAccount();

        $this->actingAsRole('driver', ['company_id' => $this->company->id]);

        $this->getJson('/api/v1/fleet/drivers/'.$this->driver->getKey().'/devices')
            ->assertStatus(403);
    }

    public function test_it_requires_authentication(): void
    {
        $this->getJson('/api/v1/fleet/drivers/'.$this->driver->getKey().'/devices')
            ->assertStatus(401);
    }

    public function test_another_companys_driver_is_refused(): void
    {
        $stranger = Company::factory()->create();
        $theirUser = User::factory()->create(['company_id' => $stranger->id]);
        $theirDriver = Driver::create([
            'company_id' => $stranger->id,
            'first_name' => 'Not',
            'last_name' => 'Yours',
            'status' => 'active',
            'user_id' => $theirUser->getKey(),
        ]);
        UserDevice::create([
            'user_id' => $theirUser->getKey(),
            'device_uuid' => 'their-handset',
            'platform' => 'android',
        ]);

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $this->getJson('/api/v1/fleet/drivers/'.$theirDriver->getKey().'/devices')
            ->assertStatus(403);
    }

    public function test_a_driver_linked_to_another_companys_user_yields_nothing(): void
    {
        /*
         * Fails closed on a link that should never exist. The driver row is
         * this company's, so the scope check passes — and if the endpoint then
         * trusted `drivers.user_id` alone, it would read a stranger's handsets.
         */
        $stranger = Company::factory()->create();
        $foreignUser = User::factory()->create(['company_id' => $stranger->id]);
        UserDevice::create([
            'user_id' => $foreignUser->getKey(),
            'device_uuid' => 'foreign-handset',
            'platform' => 'android',
            'device_name' => 'Should never appear',
        ]);

        $this->driver->forceFill(['user_id' => $foreignUser->getKey()])->save();

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $this->assertSame([], $this->devices());
    }

    public function test_it_never_returns_another_users_device_from_the_same_company(): void
    {
        // The join is on the *linked* user, not on the company.
        $this->withAccount();

        $colleague = User::factory()->create(['company_id' => $this->company->id]);
        UserDevice::create([
            'user_id' => $colleague->getKey(),
            'device_uuid' => 'colleague-handset',
            'platform' => 'android',
            'device_name' => 'Somebody else phone',
        ]);

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $this->assertSame([], $this->devices());
    }

    public function test_a_user_id_in_the_query_string_is_ignored(): void
    {
        // Ownership comes from the route binding and the server-side link.
        $this->withAccount();

        $colleague = User::factory()->create(['company_id' => $this->company->id]);
        UserDevice::create([
            'user_id' => $colleague->getKey(),
            'device_uuid' => 'colleague-handset',
            'platform' => 'android',
        ]);

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $response = $this->getJson(
            '/api/v1/fleet/drivers/'.$this->driver->getKey().'/devices'
            .'?user_id='.$colleague->getKey().'&company_id='.$this->company->id,
        )->assertStatus(200);

        $this->assertSame([], $response->json('data'));
    }

    // ------------------------------------------------------- what is sent ---

    public function test_it_does_not_expose_the_devices_position(): void
    {
        /*
         * The reason this does not reuse DeviceResource. A company manager
         * holds `drivers.view` and deliberately holds no location permission,
         * so a latitude reaching them through a page about app installation
         * would be a leak by accident — the kind that is only ever found later.
         */
        $this->withAccount();
        $this->device()->forceFill([
            'last_latitude' => 14.5995,
            'last_longitude' => 120.9842,
            'last_location_at' => now(),
        ])->save();

        $this->actingAsRole('company_manager', ['company_id' => $this->company->id]);

        $payload = json_encode($this->devices());

        $this->assertStringNotContainsString('14.5995', $payload);
        $this->assertStringNotContainsString('120.9842', $payload);
        $this->assertStringNotContainsString('last_location', $payload);
        $this->assertStringNotContainsString('latitude', $payload);
    }
}
