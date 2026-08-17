<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Fleet\Models\DeviceLocation;
use App\Domain\Fleet\Services\LocationRetentionService;
use App\Domain\User\Models\AuditLog;
use App\Domain\User\Models\Setting;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserDevice;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Retention is the one setting in the platform whose job is to destroy data,
 * so most of what is worth testing is the circumstances in which it must not.
 */
class LocationRetentionSettingTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = '/api/v1/admin/settings/privacy';

    private const UPDATE = '/api/v1/admin/settings/privacy/location-retention';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    private function seedLocations(int $count, callable $recordedAt): UserDevice
    {
        $vehicle = Vehicle::factory()->create();
        $device = UserDevice::create([
            'user_id' => $vehicle->owner_id,
            'vehicle_id' => $vehicle->getKey(),
            'device_uuid' => 'retention-'.uniqid(),
            'platform' => 'android',
        ]);

        for ($i = 0; $i < $count; $i++) {
            DeviceLocation::insertOrIgnore([[
                'device_id' => $device->getKey(),
                'vehicle_id' => $vehicle->getKey(),
                'latitude' => 14.5 + $i / 10000,
                'longitude' => 120.9 + $i / 10000,
                'recorded_at' => $recordedAt($i),
                'received_at' => $recordedAt($i),
            ]]);
        }

        return $device;
    }

    // ------------------------------------------------------------- reading ---

    public function test_an_administrator_can_view_the_retention_setting(): void
    {
        $this->actingAsRole('super_admin');

        $response = $this->getJson(self::ENDPOINT);

        $this->assertApiSuccess($response);
        $this->assertSame(30, $response->json('data.location_retention.days'));
        $this->assertSame(365, $response->json('data.location_retention.maximum_days'));
    }

    public function test_an_unconfigured_period_reports_itself_as_provisional(): void
    {
        $this->actingAsRole('super_admin');

        $response = $this->getJson(self::ENDPOINT);

        $this->assertSame('provisional', $response->json('data.location_retention.status'));
        $this->assertFalse($response->json('data.location_retention.is_configured'));
        $this->assertTrue(
            $response->json('data.location_retention.requires_approval'),
            'A fallback nobody chose must never present itself as approved.',
        );
    }

    // ------------------------------------------------------------ authority ---

    public function test_a_driver_cannot_read_the_setting(): void
    {
        $this->actingAsRole('driver');

        $this->getJson(self::ENDPOINT)->assertStatus(403);
    }

    public function test_a_fleet_manager_cannot_change_the_retention_period(): void
    {
        // Fleet managers administer vehicles and see location history. Deciding
        // how long a driver's movements are kept is a different question.
        $this->actingAsRole('fleet_manager');

        $this->putJson(self::UPDATE, ['days' => 90])->assertStatus(403);

        $this->assertDatabaseCount('settings', 0);
    }

    public function test_an_unauthenticated_request_is_rejected(): void
    {
        $this->putJson(self::UPDATE, ['days' => 90])->assertStatus(401);
    }

    // ----------------------------------------------------------- validation ---

    public function test_zero_days_is_rejected(): void
    {
        $this->actingAsRole('super_admin');

        $this->assertApiValidationErrors($this->putJson(self::UPDATE, ['days' => 0]), 'days');
        $this->assertDatabaseCount('settings', 0);
    }

    public function test_a_negative_period_is_rejected(): void
    {
        $this->actingAsRole('super_admin');

        $this->assertApiValidationErrors($this->putJson(self::UPDATE, ['days' => -10]), 'days');
    }

    public function test_a_period_beyond_the_maximum_is_rejected(): void
    {
        $this->actingAsRole('super_admin');

        $this->assertApiValidationErrors($this->putJson(self::UPDATE, ['days' => 4000]), 'days');
    }

    public function test_a_fractional_period_is_rejected(): void
    {
        $this->actingAsRole('super_admin');

        $this->assertApiValidationErrors($this->putJson(self::UPDATE, ['days' => 12.5]), 'days');
    }

    // -------------------------------------------------------------- writing ---

    public function test_an_administrator_can_save_a_retention_period(): void
    {
        $admin = $this->actingAsRole('super_admin');

        $response = $this->putJson(self::UPDATE, ['days' => 60]);

        $this->assertApiSuccess($response);
        $this->assertSame(60, $response->json('data.days'));

        $this->assertDatabaseHas('settings', [
            'group' => LocationRetentionService::GROUP,
            'key' => LocationRetentionService::KEY,
            'updated_by' => $admin->getKey(),
        ]);
    }

    public function test_a_configured_period_stops_being_provisional(): void
    {
        $this->actingAsRole('super_admin');

        // 30 matches the fallback, so this also proves status is decided by
        // whether somebody chose the value, not by what the value happens to be.
        $this->putJson(self::UPDATE, ['days' => 30]);

        $response = $this->getJson(self::ENDPOINT);

        $this->assertSame('approved', $response->json('data.location_retention.status'));
        $this->assertTrue($response->json('data.location_retention.is_configured'));
    }

    public function test_the_change_is_audited_with_the_previous_value(): void
    {
        $admin = $this->actingAsRole('super_admin');

        $this->putJson(self::UPDATE, ['days' => 45]);
        $this->putJson(self::UPDATE, ['days' => 90]);

        $entry = AuditLog::where('event', 'setting.updated')->latest('id')->first();

        $this->assertNotNull($entry);
        $this->assertSame($admin->getKey(), $entry->user_id);
        $this->assertSame(Setting::class, $entry->auditable_type);
        $this->assertSame(45, $entry->old_values['value']);
        $this->assertSame(90, $entry->new_values['value']);
        $this->assertNotNull($entry->created_at);
        $this->assertNotNull($entry->ip_address, 'The request context is part of the record.');
    }

    public function test_the_environment_cannot_override_a_configured_value(): void
    {
        $this->actingAsRole('super_admin');

        $this->putJson(self::UPDATE, ['days' => 90]);
        config(['fip.location.retention_days' => 7]);

        $this->assertSame(90, app(LocationRetentionService::class)->days());
        $this->assertSame(
            'requires_review',
            app(LocationRetentionService::class)->status(),
            'A disagreeing .env is not silently obeyed, but it is surfaced.',
        );
    }

    // --------------------------------------------------------------- pruner ---

    public function test_the_pruner_uses_the_configured_period(): void
    {
        $this->actingAsRole('super_admin');
        $this->putJson(self::UPDATE, ['days' => 10]);

        // Rows at 1, 3, 5 ... 39 days old. At 10 days, four survive.
        $this->seedLocations(20, fn (int $i) => now()->subDays($i * 2 + 1));

        Artisan::call('model:prune', ['--path' => 'app/Domain']);

        $this->assertSame(5, DeviceLocation::count());
        $this->assertSame(
            0,
            DeviceLocation::where('recorded_at', '<', now()->subDays(10))->count(),
            'Nothing older than the configured period may survive.',
        );
    }

    public function test_locations_newer_than_the_period_are_kept(): void
    {
        $this->actingAsRole('super_admin');
        $this->putJson(self::UPDATE, ['days' => 30]);

        $this->seedLocations(5, fn (int $i) => now()->subDays($i));

        Artisan::call('model:prune', ['--path' => 'app/Domain']);

        $this->assertSame(5, DeviceLocation::count(), 'Recent history is not touched.');
    }

    public function test_an_unconfigured_setting_does_not_prune_destructively(): void
    {
        // Nothing configured and no fallback: the pruner must delete nothing at
        // all rather than treating the absent value as "older than zero days".
        config(['fip.location.retention_days' => 0]);

        $this->seedLocations(6, fn (int $i) => now()->subYears(2)->subDays($i));

        Artisan::call('model:prune', ['--path' => 'app/Domain']);

        $this->assertSame(6, DeviceLocation::count(), 'A missing period is not permission to erase history.');
    }

    public function test_extending_retention_does_not_delete_records_early(): void
    {
        $this->actingAsRole('super_admin');
        $this->putJson(self::UPDATE, ['days' => 30]);

        // Rows at 20, 25, 40 and 50 days old.
        $ages = [20, 25, 40, 50];
        $this->seedLocations(4, fn (int $i) => now()->subDays($ages[$i]));

        $this->putJson(self::UPDATE, ['days' => 60]);
        Artisan::call('model:prune', ['--path' => 'app/Domain']);

        $this->assertSame(
            4,
            DeviceLocation::count(),
            'Extending the period must retroactively protect records the old period would have removed.',
        );
    }

    public function test_shortening_retention_applies_on_the_next_prune(): void
    {
        $this->actingAsRole('super_admin');
        $this->putJson(self::UPDATE, ['days' => 60]);

        $ages = [20, 25, 40, 50];
        $this->seedLocations(4, fn (int $i) => now()->subDays($ages[$i]));

        Artisan::call('model:prune', ['--path' => 'app/Domain']);
        $this->assertSame(4, DeviceLocation::count(), 'Nothing is older than 60 days yet.');

        $this->putJson(self::UPDATE, ['days' => 30]);
        Artisan::call('model:prune', ['--path' => 'app/Domain']);

        $this->assertSame(2, DeviceLocation::count(), 'The 40 and 50 day rows fall outside the new period.');
        $this->assertSame(0, DeviceLocation::where('recorded_at', '<', now()->subDays(30))->count());
    }

    public function test_pruning_leaves_unrelated_records_alone(): void
    {
        $this->actingAsRole('super_admin');
        $this->putJson(self::UPDATE, ['days' => 10]);

        $this->seedLocations(4, fn (int $i) => now()->subDays(40 + $i));

        $vehicles = Vehicle::count();
        $devices = UserDevice::count();
        $users = User::count();

        Artisan::call('model:prune', ['--path' => 'app/Domain']);

        $this->assertSame(0, DeviceLocation::count());
        $this->assertSame($vehicles, Vehicle::count(), 'Pruning locations must not remove vehicles.');
        $this->assertSame($devices, UserDevice::count(), 'Nor the devices that reported them.');
        $this->assertSame($users, User::count());
    }
}
