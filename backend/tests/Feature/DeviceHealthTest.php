<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserDevice;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Device health: what a device reports about itself, and who may read it.
 *
 * The authorization boundary carries most of the weight here. This feature is
 * the one read in the product that crosses from "a device belongs to a person"
 * into "a device is fleet equipment", and the thing that makes that defensible
 * is the vehicle association. Several tests below exist only to prove a manager
 * still cannot see a handset that is not carrying one of their vehicles.
 */
class DeviceHealthTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $driver;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        $this->company = Company::factory()->create();
        $this->driver = User::factory()->create(['company_id' => $this->company->id]);
        $this->driver->assignRole('driver');
        $this->vehicle = Vehicle::factory()->forCompany($this->company->id)->create();

        config([
            'fip.device_health.offline_after_minutes' => 15,
            'fip.device_health.battery_stale_after_minutes' => 60,
            'fip.device_health.low_battery_pct' => 20,
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * The battery columns and revoked_at are deliberately not fillable — only
     * the device reporting about itself and revoke() may write them — so a
     * fixture has to force them past the same guard the application respects.
     */
    private function device(array $overrides = []): UserDevice
    {
        $guarded = ['battery_percentage', 'battery_state', 'battery_updated_at', 'revoked_at'];

        $device = UserDevice::create(array_merge([
            'user_id' => $this->driver->getKey(),
            'vehicle_id' => $this->vehicle->getKey(),
            'device_uuid' => 'install-'.uniqid(),
            'platform' => 'android',
            'last_seen_at' => now(),
        ], array_diff_key($overrides, array_flip($guarded))));

        $forced = array_intersect_key($overrides, array_flip($guarded));

        if ($forced !== []) {
            $device->forceFill($forced)->save();
        }

        return $device;
    }

    /** @param array<string, mixed> $overrides */
    private function report(array $overrides = [], string $uuid = 'install-health')
    {
        return $this->withHeaders(['X-Device-Id' => $uuid])
            ->postJson('/api/v1/devices/health', array_merge([
                'battery_percentage' => 64,
                'battery_state' => 'discharging',
            ], $overrides));
    }

    // ------------------------------------------------------- self-reporting ---

    public function test_a_device_reports_its_own_battery(): void
    {
        $this->actingAs($this->driver, 'api');
        $device = $this->device(['device_uuid' => 'install-health']);

        $this->report()->assertStatus(200);

        $device->refresh();
        $this->assertSame(64, $device->battery_percentage);
        $this->assertSame('discharging', $device->battery_state);
        $this->assertNotNull($device->battery_updated_at);
    }

    public function test_reporting_health_counts_as_being_seen(): void
    {
        // A parked vehicle sends no positions, because the movement filter
        // drops them. Without this, a device that is awake and reporting would
        // drift into "offline" purely for standing still.
        $this->actingAs($this->driver, 'api');
        $device = $this->device(['device_uuid' => 'install-health', 'last_seen_at' => now()->subHours(3)]);

        $this->report()->assertStatus(200);

        $this->assertTrue($device->refresh()->isOnline());
    }

    public function test_an_implausible_percentage_is_refused(): void
    {
        // iOS reports -1 when the level is unavailable. Stored unclamped that
        // would read as a critically flat battery and trip every alert.
        $this->actingAs($this->driver, 'api');
        $this->device(['device_uuid' => 'install-health']);

        $this->report(['battery_percentage' => -1])->assertStatus(422);
        $this->report(['battery_percentage' => 101])->assertStatus(422);
    }

    public function test_an_unknown_battery_state_is_refused(): void
    {
        $this->actingAs($this->driver, 'api');
        $this->device(['device_uuid' => 'install-health']);

        $this->report(['battery_state' => 'combusting'])->assertStatus(422);
    }

    public function test_a_reading_from_the_future_is_clamped_to_now(): void
    {
        // A phone with a wrong clock would otherwise park its reading in the
        // future, where every staleness check treats it as permanently fresh.
        $this->actingAs($this->driver, 'api');
        $device = $this->device(['device_uuid' => 'install-health']);

        $this->report(['recorded_at' => now()->addDays(2)->toIso8601String()])->assertStatus(200);

        $this->assertFalse($device->refresh()->battery_updated_at->isFuture());
    }

    public function test_a_revoked_device_may_not_report(): void
    {
        $this->actingAs($this->driver, 'api');
        $this->device(['device_uuid' => 'install-health', 'revoked_at' => now()]);

        $this->report()->assertStatus(403);
    }

    public function test_a_device_cannot_report_on_behalf_of_another(): void
    {
        // The device is resolved from the session plus the header. Naming
        // somebody else's installation gets nowhere without being its owner.
        $other = User::factory()->create(['company_id' => $this->company->id]);
        $theirs = UserDevice::create([
            'user_id' => $other->getKey(),
            'vehicle_id' => $this->vehicle->getKey(),
            'device_uuid' => 'install-theirs',
            'platform' => 'ios',
        ]);

        $this->actingAs($this->driver, 'api');
        $this->report([], 'install-theirs')->assertStatus(403);

        $this->assertNull($theirs->refresh()->battery_percentage);
    }

    // ------------------------------------------------------- fleet visibility ---

    public function test_a_fleet_manager_sees_devices_carrying_their_vehicles(): void
    {
        $this->device(['battery_percentage' => 55, 'battery_state' => 'discharging', 'battery_updated_at' => now()]);

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $response = $this->getJson('/api/v1/fleet/devices');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertSame(55, $response->json('data.0.battery.percentage'));
        $this->assertSame($this->vehicle->plate_number, $response->json('data.0.vehicle.plate_number'));
    }

    public function test_a_personal_handset_stays_invisible_to_the_manager(): void
    {
        // The vehicle association is the entire justification for a manager
        // reading another person's device at all. Without one it is just a
        // phone, and the existing ownership rule stands.
        $this->device(['vehicle_id' => null]);

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $this->getJson('/api/v1/fleet/devices')->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_a_manager_cannot_see_another_companys_devices(): void
    {
        $this->device();

        $other = Company::factory()->create();
        $this->actingAsRole('fleet_manager', ['company_id' => $other->id]);

        $this->getJson('/api/v1/fleet/devices')->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_a_driver_may_not_read_the_fleet_listing(): void
    {
        $this->device();
        $this->actingAsRole('driver', ['company_id' => $this->company->id]);

        $this->getJson('/api/v1/fleet/devices')->assertStatus(403);
    }

    public function test_a_manager_with_no_company_sees_nothing(): void
    {
        $this->device();
        $this->actingAsRole('fleet_manager', ['company_id' => null]);

        $this->getJson('/api/v1/fleet/devices')->assertStatus(403);
    }

    public function test_a_platform_administrator_sees_across_companies(): void
    {
        $this->device();

        $otherCompany = Company::factory()->create();
        $otherDriver = User::factory()->create(['company_id' => $otherCompany->id]);
        UserDevice::create([
            'user_id' => $otherDriver->getKey(),
            'vehicle_id' => Vehicle::factory()->forCompany($otherCompany->id)->create()->getKey(),
            'device_uuid' => 'install-elsewhere',
            'platform' => 'ios',
        ]);

        $this->actingAsRole('super_admin');

        $this->getJson('/api/v1/fleet/devices')->assertStatus(200)->assertJsonCount(2, 'data');
    }

    // ------------------------------------------------------------- detail ---

    public function test_the_detail_view_is_scoped_to_the_company(): void
    {
        // A detail route takes an id from the caller, so it re-checks the
        // tenancy the listing scope applies. Otherwise knowing an id would be
        // enough to read any device's health.
        $device = $this->device();

        $other = Company::factory()->create();
        $this->actingAsRole('fleet_manager', ['company_id' => $other->id]);

        $this->getJson("/api/v1/fleet/devices/{$device->getKey()}")->assertStatus(403);
    }

    public function test_an_owner_reads_their_own_device_detail(): void
    {
        $device = $this->device();
        $this->actingAs($this->driver, 'api');

        $this->getJson("/api/v1/fleet/devices/{$device->getKey()}")->assertStatus(200);
    }

    public function test_the_health_payload_never_carries_device_credentials(): void
    {
        // device_uuid is what the device authenticates with, and fcm_token is a
        // push credential. A health dashboard has no use for either.
        $this->device(['fcm_token' => 'push-secret-value']);

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $body = $this->getJson('/api/v1/fleet/devices')->getContent();

        $this->assertStringNotContainsString('device_uuid', $body);
        $this->assertStringNotContainsString('push-secret-value', $body);
        $this->assertStringNotContainsString('biometric_key', $body);
    }

    // ------------------------------------------------------------ filters ---

    public function test_the_offline_filter_includes_a_device_never_seen(): void
    {
        // NULL > x is NULL in SQL, so a never-seen device falls out of both
        // filters unless offline asks for it explicitly.
        $this->device(['last_seen_at' => null]);
        $this->device(['device_uuid' => 'install-fresh', 'last_seen_at' => now()]);

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $this->getJson('/api/v1/fleet/devices?filter=offline')->assertStatus(200)->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/fleet/devices?filter=online')->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_a_charging_device_is_not_low(): void
    {
        // A phone on 5% on a dashboard charger needs nobody's attention.
        $this->device([
            'battery_percentage' => 5,
            'battery_state' => 'charging',
            'battery_updated_at' => now(),
        ]);

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $this->getJson('/api/v1/fleet/devices?filter=low_battery')->assertStatus(200)->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/fleet/devices?filter=charging')->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_a_stale_reading_is_not_treated_as_a_low_battery(): void
    {
        // The phone died yesterday at 4%. That is history, not a fleet alert,
        // and showing it as current sends someone looking for a parked van.
        $this->device([
            'battery_percentage' => 4,
            'battery_state' => 'discharging',
            'battery_updated_at' => now()->subDay(),
        ]);

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $response = $this->getJson('/api/v1/fleet/devices?filter=low_battery');
        $response->assertStatus(200)->assertJsonCount(0, 'data');

        // Still reported, but flagged as not current rather than hidden.
        $all = $this->getJson('/api/v1/fleet/devices');
        $this->assertFalse($all->json('data.0.battery.is_fresh'));
        $this->assertFalse($all->json('data.0.battery.is_low'));
    }

    public function test_a_low_battery_is_reported_as_low(): void
    {
        $this->device([
            'battery_percentage' => 11,
            'battery_state' => 'discharging',
            'battery_updated_at' => now(),
        ]);

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $response = $this->getJson('/api/v1/fleet/devices?filter=low_battery');
        $response->assertStatus(200)->assertJsonCount(1, 'data');
        $this->assertTrue($response->json('data.0.battery.is_low'));
    }

    public function test_an_unrecognised_filter_is_refused_rather_than_ignored(): void
    {
        // match() falls through to "everything", so an unvalidated filter would
        // quietly widen the result instead of failing.
        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $this->getJson('/api/v1/fleet/devices?filter=all')->assertStatus(422);
    }

    public function test_the_summary_counts_each_filter(): void
    {
        $this->device(['battery_percentage' => 8, 'battery_state' => 'discharging', 'battery_updated_at' => now()]);
        $this->device(['device_uuid' => 'install-b', 'last_seen_at' => now()->subDay()]);

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $summary = $this->getJson('/api/v1/fleet/devices')->json('meta.summary');

        $this->assertSame(2, $summary['total']);
        $this->assertSame(1, $summary['online']);
        $this->assertSame(1, $summary['offline']);
        $this->assertSame(1, $summary['low_battery']);
        $this->assertSame(0, $summary['charging']);
    }

    // ---------------------------------------------------- tracking untouched ---

    public function test_a_revoked_device_reads_as_not_tracking(): void
    {
        // Online and tracking are different questions: a revoked handset still
        // talks to the API, and reporting only one of them would mislead.
        $this->device(['revoked_at' => now(), 'last_seen_at' => now()]);

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $response = $this->getJson('/api/v1/fleet/devices');

        $this->assertTrue($response->json('data.0.is_online'));
        $this->assertFalse($response->json('data.0.is_tracking'));
        $this->assertTrue($response->json('data.0.is_revoked'));
    }
}
