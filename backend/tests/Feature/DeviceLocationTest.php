<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Fleet\Models\DeviceLocation;
use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use App\Domain\User\Models\UserDevice;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Location ingestion is the point where a client's claims meet the server's
 * record of who owns what. Almost every test here is about refusing something.
 */
class DeviceLocationTest extends TestCase
{
    use RefreshDatabase;

    private User $driver;

    private Vehicle $vehicle;

    private UserDevice $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        $company = Company::factory()->create();
        $this->driver = $this->actingAsRole('driver', ['company_id' => $company->id]);
        $this->vehicle = Vehicle::factory()->forCompany($company->id)->create();

        $this->device = UserDevice::create([
            'user_id' => $this->driver->getKey(),
            'vehicle_id' => $this->vehicle->getKey(),
            'device_uuid' => 'install-tracked',
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);
    }

    /** @param array<int, array<string, mixed>> $points */
    private function submit(array $points, ?string $deviceUuid = 'install-tracked')
    {
        $headers = $deviceUuid !== null ? ['X-Device-Id' => $deviceUuid] : [];

        return $this->withHeaders($headers)
            ->postJson('/api/v1/devices/location', ['points' => $points]);
    }

    /** @param array<string, mixed> $overrides */
    private function point(array $overrides = []): array
    {
        return array_merge([
            'latitude' => 14.5995,
            'longitude' => 120.9842,
            'accuracy_m' => 8.5,
            'speed_kph' => 42.3,
            'heading_deg' => 180,
            'recorded_at' => now()->subMinute()->toIso8601String(),
        ], $overrides);
    }

    // ---------------------------------------------------------- happy path ---

    public function test_a_registered_device_can_report_a_position(): void
    {
        $response = $this->submit([$this->point()]);

        $this->assertApiSuccess($response);
        $this->assertSame(1, $response->json('data.accepted'));

        $this->assertDatabaseHas('device_locations', [
            'device_id' => $this->device->id,
            'vehicle_id' => $this->vehicle->id,
        ]);
    }

    public function test_the_vehicle_is_stamped_from_the_server_not_the_body(): void
    {
        $foreign = Vehicle::factory()->create(['owner_id' => User::factory()->create()->id]);

        $this->submit([$this->point(['vehicle_id' => $foreign->id, 'device_id' => 999])]);

        $stored = DeviceLocation::first();

        $this->assertSame($this->vehicle->id, $stored->vehicle_id, 'Ownership comes from the registration.');
        $this->assertSame($this->device->id, $stored->device_id);
    }

    public function test_it_records_both_clocks(): void
    {
        $this->submit([$this->point()]);

        $stored = DeviceLocation::first();

        $this->assertNotNull($stored->recorded_at);
        $this->assertNotNull($stored->received_at);
    }

    public function test_a_batch_is_accepted_in_one_call(): void
    {
        $points = [];
        for ($i = 5; $i >= 1; $i--) {
            $points[] = $this->point(['recorded_at' => now()->subMinutes($i)->toIso8601String()]);
        }

        $response = $this->submit($points);

        $this->assertSame(5, $response->json('data.accepted'));
    }

    public function test_a_replayed_batch_does_not_duplicate(): void
    {
        $points = [$this->point(['recorded_at' => now()->subMinutes(3)->toIso8601String()])];

        $this->submit($points);
        $second = $this->submit($points);

        $this->assertSame(0, $second->json('data.accepted'));
        $this->assertSame(1, $second->json('data.duplicates'));
        $this->assertSame(1, DeviceLocation::count());
    }

    public function test_it_updates_the_devices_cached_position(): void
    {
        $this->submit([$this->point(['latitude' => 10.5, 'longitude' => 122.5])]);

        $fresh = $this->device->fresh();

        $this->assertSame(10.5, $fresh->last_latitude);
        $this->assertSame(122.5, $fresh->last_longitude);
        $this->assertNotNull($fresh->last_location_at);
    }

    public function test_a_late_flush_does_not_rewind_the_cached_position(): void
    {
        $this->submit([$this->point(['latitude' => 10.0, 'recorded_at' => now()->subMinute()->toIso8601String()])]);
        $this->submit([$this->point(['latitude' => 20.0, 'recorded_at' => now()->subHours(3)->toIso8601String()])]);

        $this->assertSame(
            10.0,
            $this->device->fresh()->last_latitude,
            'An offline queue arriving late must not move the dashboard backwards.',
        );
    }

    // ------------------------------------------------------------ security ---

    public function test_an_unauthenticated_submission_is_rejected(): void
    {
        // flushHeaders, not just forgetGuards: setUp installed a bearer token
        // via actingAsRole, and clearing the resolved guard while leaving the
        // Authorization header in place simply re-authenticates on the next
        // request. The header is what has to go.
        $this->flushHeaders();
        $this->app['auth']->forgetGuards();

        $this->withHeaders(['X-Device-Id' => 'install-tracked'])
            ->postJson('/api/v1/devices/location', ['points' => [$this->point()]])
            ->assertStatus(401);
    }

    public function test_an_unregistered_device_header_is_rejected(): void
    {
        $this->assertApiError($this->submit([$this->point()], 'install-unknown'), 'device_not_registered', 403);
    }

    public function test_a_missing_device_header_is_rejected(): void
    {
        $this->assertApiError($this->submit([$this->point()], null), 'device_not_registered', 403);
    }

    public function test_another_users_device_identifier_is_not_usable(): void
    {
        // The header alone is a client-supplied string. Impersonation needs the
        // session too, and the session belongs to someone else.
        $stranger = User::factory()->create();
        UserDevice::create([
            'user_id' => $stranger->getKey(),
            'vehicle_id' => $this->vehicle->getKey(),
            'device_uuid' => 'install-stranger',
            'platform' => 'ios',
        ]);

        $this->assertApiError($this->submit([$this->point()], 'install-stranger'), 'device_not_registered', 403);
    }

    public function test_a_revoked_device_cannot_report(): void
    {
        $this->device->forceFill(['revoked_at' => now()])->save();

        $this->assertApiError($this->submit([$this->point()]), 'device_revoked', 403);
        $this->assertSame(0, DeviceLocation::count());
    }

    public function test_a_device_with_no_vehicle_cannot_report(): void
    {
        $this->device->forceFill(['vehicle_id' => null])->save();

        $this->assertApiError($this->submit([$this->point()]), 'device_unassigned', 403);
    }

    // ---------------------------------------------------------- validation ---

    public function test_it_rejects_an_impossible_latitude(): void
    {
        $this->assertApiValidationErrors($this->submit([$this->point(['latitude' => 91])]), 'points.0.latitude');
    }

    public function test_it_rejects_an_impossible_longitude(): void
    {
        $this->assertApiValidationErrors($this->submit([$this->point(['longitude' => 181])]), 'points.0.longitude');
    }

    public function test_it_rejects_null_island(): void
    {
        // A device that failed to get a fix and defaulted to (0,0) would
        // otherwise put a confident pin in the Gulf of Guinea.
        $response = $this->submit([$this->point(['latitude' => 0, 'longitude' => 0])]);

        $this->assertSame(0, $response->json('data.accepted'));
        $this->assertStringContainsString('null island', $response->json('data.rejected.0.reason'));
    }

    public function test_it_rejects_a_timestamp_from_the_future(): void
    {
        $response = $this->submit([$this->point(['recorded_at' => now()->addHours(2)->toIso8601String()])]);

        $this->assertSame(0, $response->json('data.accepted'));
        $this->assertStringContainsString('future', $response->json('data.rejected.0.reason'));
    }

    public function test_it_rejects_a_uselessly_inaccurate_fix(): void
    {
        config(['fip.location.max_accuracy_metres' => 1000]);

        $response = $this->submit([$this->point(['accuracy_m' => 5000])]);

        $this->assertSame(0, $response->json('data.accepted'));
        $this->assertStringContainsString('accuracy', $response->json('data.rejected.0.reason'));
    }

    public function test_one_bad_point_does_not_reject_the_whole_batch(): void
    {
        // Otherwise a device with one wild fix retries the same poisoned batch
        // forever and never delivers the good positions behind it.
        $response = $this->submit([
            $this->point(['recorded_at' => now()->subMinutes(4)->toIso8601String()]),
            $this->point(['latitude' => 0, 'longitude' => 0, 'recorded_at' => now()->subMinutes(3)->toIso8601String()]),
            $this->point(['recorded_at' => now()->subMinutes(2)->toIso8601String()]),
        ]);

        $this->assertSame(2, $response->json('data.accepted'));
        $this->assertCount(1, $response->json('data.rejected'));
    }

    public function test_an_oversized_batch_is_refused(): void
    {
        config(['fip.location.max_batch_size' => 3]);

        $points = [];
        for ($i = 10; $i >= 1; $i--) {
            $points[] = $this->point(['recorded_at' => now()->subMinutes($i)->toIso8601String()]);
        }

        $this->assertApiValidationErrors($this->submit($points), 'points');
    }
}
