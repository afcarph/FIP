<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Fleet\Models\DeviceLocation;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserDevice;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tidying up browser registrations.
 *
 * Signing in on the web writes a device row and the browser's identifier lives
 * in localStorage, so a cleared profile or a private window mints another that
 * nothing removes. They are session identities, not devices.
 *
 * What matters here is everything the pruner must refuse to touch. A handset
 * carries the history a fleet is measured on, and `device_locations.device_id`
 * cascades on delete — so a wrong row taken here does not just lose a
 * registration, it loses a vehicle's track.
 */
class WebDeviceRetentionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        $this->user = User::factory()->create();

        config(['fip.device_health.web_registration_retention_days' => 90]);
    }

    private function device(array $attributes = []): UserDevice
    {
        return UserDevice::create([
            'user_id' => $this->user->getKey(),
            'device_uuid' => 'uuid-'.uniqid(),
            'platform' => 'web',
            ...$attributes,
        ]);
    }

    private function prune(): int
    {
        return $this->artisan('model:prune', ['--model' => [UserDevice::class]])->run();
    }

    public function test_a_browser_silent_past_the_window_is_removed(): void
    {
        $stale = $this->device(['last_seen_at' => now()->subDays(120)]);

        $this->prune();

        $this->assertDatabaseMissing('user_devices', ['id' => $stale->getKey()]);
    }

    public function test_a_browser_still_in_use_is_left_alone(): void
    {
        $recent = $this->device(['last_seen_at' => now()->subDays(10)]);

        $this->prune();

        $this->assertDatabaseHas('user_devices', ['id' => $recent->getKey()]);
    }

    public function test_a_registration_that_never_reported_is_measured_from_when_it_was_made(): void
    {
        // last_seen_at is null on a registration that never came back. Without
        // this branch those rows would be immortal — the exact rows most
        // likely to be junk.
        $never = $this->device(['last_seen_at' => null]);
        $never->forceFill(['created_at' => now()->subDays(200)])->save();

        $this->prune();

        $this->assertDatabaseMissing('user_devices', ['id' => $never->getKey()]);
    }

    public function test_a_new_registration_that_has_not_reported_yet_survives(): void
    {
        $fresh = $this->device(['last_seen_at' => null]);

        $this->prune();

        $this->assertDatabaseHas('user_devices', ['id' => $fresh->getKey()]);
    }

    public function test_a_handset_is_never_pruned_however_quiet_it_goes(): void
    {
        // A driver on leave for a season must not lose their phone's
        // registration, and with it the link to everything it reported.
        $phone = $this->device(['platform' => 'ios', 'last_seen_at' => now()->subDays(400)]);

        $this->prune();

        $this->assertDatabaseHas('user_devices', ['id' => $phone->getKey()]);
    }

    public function test_a_device_attached_to_a_vehicle_is_in_service_by_definition(): void
    {
        $vehicle = Vehicle::factory()->create();
        $attached = $this->device([
            'vehicle_id' => $vehicle->getKey(),
            'last_seen_at' => now()->subDays(365),
        ]);

        $this->prune();

        $this->assertDatabaseHas('user_devices', ['id' => $attached->getKey()]);
    }

    public function test_a_device_holding_positions_is_never_taken_with_its_track(): void
    {
        // device_locations.device_id cascades on delete. This is the condition
        // that stops a tidy-up quietly erasing a vehicle's history.
        $carrying = $this->device(['last_seen_at' => now()->subDays(365)]);

        DeviceLocation::create([
            'device_id' => $carrying->getKey(),
            'vehicle_id' => null,
            'latitude' => 14.55,
            'longitude' => 121.02,
            'recorded_at' => now()->subDays(360),
            'received_at' => now()->subDays(360),
        ]);

        $this->prune();

        $this->assertDatabaseHas('user_devices', ['id' => $carrying->getKey()]);
        $this->assertSame(1, DeviceLocation::query()->count());
    }

    public function test_zero_days_keeps_everything(): void
    {
        // An absent or misread setting must never be read as "older than zero
        // days", which would delete every browser registration on the system.
        config(['fip.device_health.web_registration_retention_days' => 0]);

        $ancient = $this->device(['last_seen_at' => now()->subYears(5)]);

        $this->prune();

        $this->assertDatabaseHas('user_devices', ['id' => $ancient->getKey()]);
    }
}
