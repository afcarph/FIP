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
 * Reading a track is the most invasive thing this feature permits, so the
 * question these tests ask is mostly "who is refused".
 */
class LocationHistoryTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Vehicle $vehicle;

    private UserDevice $device;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        $this->company = Company::factory()->create();
        $this->vehicle = Vehicle::factory()->forCompany($this->company->id)->create();

        $this->device = UserDevice::create([
            'user_id' => User::factory()->create(['company_id' => $this->company->id])->getKey(),
            'vehicle_id' => $this->vehicle->getKey(),
            'device_uuid' => 'install-history',
            'platform' => 'android',
        ]);
    }

    private function seedTrack(int $count = 5, int $spacingMinutes = 10): void
    {
        for ($i = $count; $i >= 1; $i--) {
            DeviceLocation::create([
                'device_id' => $this->device->getKey(),
                'vehicle_id' => $this->vehicle->getKey(),
                'latitude' => 14.5 + ($i / 1000),
                'longitude' => 120.9 + ($i / 1000),
                'recorded_at' => now()->subMinutes($i * $spacingMinutes),
                'received_at' => now()->subMinutes($i * $spacingMinutes),
            ]);
        }
    }

    // ------------------------------------------------------------- reading ---

    public function test_a_fleet_manager_can_read_the_history(): void
    {
        $this->seedTrack();
        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $response = $this->getJson("/api/v1/fleet/vehicles/{$this->vehicle->id}/locations");

        $this->assertApiSuccess($response);
        $this->assertCount(5, $response->json('data'));
    }

    public function test_history_is_returned_oldest_first(): void
    {
        $this->seedTrack();
        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $rows = $this->getJson("/api/v1/fleet/vehicles/{$this->vehicle->id}/locations")->json('data');

        $timestamps = array_column($rows, 'recorded_at');
        $sorted = $timestamps;
        sort($sorted);

        $this->assertSame($sorted, $timestamps, 'A track is read forwards.');
    }

    public function test_the_response_reports_the_window_it_used(): void
    {
        $this->seedTrack();
        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $meta = $this->getJson("/api/v1/fleet/vehicles/{$this->vehicle->id}/locations")->json('meta');

        $this->assertArrayHasKey('window', $meta);
        $this->assertNotNull($meta['window']['from']);
    }

    // ------------------------------------------------------- authorisation ---

    public function test_company_manager_is_refused_history_without_the_permission(): void
    {
        // company_manager holds devices.location.view but deliberately not
        // devices.location.history: seeing the fleet now and replaying a
        // driver's week are different asks.
        $this->seedTrack();
        $this->actingAsRole('company_manager', ['company_id' => $this->company->id]);

        $this->getJson("/api/v1/fleet/vehicles/{$this->vehicle->id}/locations")->assertStatus(403);
    }

    public function test_another_companys_manager_cannot_read_the_history(): void
    {
        $this->seedTrack();
        $this->actingAsRole('fleet_manager', ['company_id' => Company::factory()->create()->id]);

        $this->getJson("/api/v1/fleet/vehicles/{$this->vehicle->id}/locations")->assertStatus(403);
    }

    public function test_history_requires_authentication(): void
    {
        $this->seedTrack();

        $this->getJson("/api/v1/fleet/vehicles/{$this->vehicle->id}/locations")->assertStatus(401);
    }

    // ------------------------------------------------------------- bounds ---

    public function test_a_window_wider_than_the_limit_is_refused(): void
    {
        config(['fip.location.max_history_days' => 7]);
        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $this->assertApiError(
            // urlencode: an ISO8601 offset contains '+', which a query string
            // decodes as a space, and the date rule then rejects it before the
            // window check is ever reached.
            $this->getJson(sprintf(
                '/api/v1/fleet/vehicles/%d/locations?from=%s&to=%s',
                $this->vehicle->id,
                urlencode(now()->subDays(60)->toIso8601String()),
                urlencode(now()->toIso8601String()),
            )),
            'history_window_too_wide',
            422,
        );
    }

    public function test_an_inverted_range_is_rejected(): void
    {
        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $this->assertApiValidationErrors(
            $this->getJson(sprintf(
                '/api/v1/fleet/vehicles/%d/locations?from=%s&to=%s',
                $this->vehicle->id,
                urlencode(now()->toIso8601String()),
                urlencode(now()->subDays(3)->toIso8601String()),
            )),
            'to',
        );
    }

    public function test_the_page_size_is_capped(): void
    {
        config(['fip.location.max_history_rows' => 10]);
        $this->seedTrack(20, 1);
        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $response = $this->getJson("/api/v1/fleet/vehicles/{$this->vehicle->id}/locations?per_page=9999");

        $this->assertLessThanOrEqual(10, count($response->json('data')));
    }

    // ------------------------------------------------------ latest position ---

    public function test_the_fleet_map_endpoint_returns_one_row_per_vehicle(): void
    {
        $this->device->forceFill([
            'last_latitude' => 14.6,
            'last_longitude' => 120.98,
            'last_location_at' => now(),
        ])->save();

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $response = $this->getJson('/api/v1/fleet/locations');

        $this->assertApiSuccess($response);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame($this->vehicle->id, $response->json('data.0.vehicle_id'));
    }

    public function test_the_fleet_map_excludes_other_companies(): void
    {
        $this->device->forceFill([
            'last_latitude' => 14.6, 'last_longitude' => 120.98, 'last_location_at' => now(),
        ])->save();

        $this->actingAsRole('fleet_manager', ['company_id' => Company::factory()->create()->id]);

        $this->assertCount(0, $this->getJson('/api/v1/fleet/locations')->json('data'));
    }

    public function test_a_revoked_devices_position_leaves_the_fleet_map(): void
    {
        $this->device->forceFill([
            'last_latitude' => 14.6, 'last_longitude' => 120.98,
            'last_location_at' => now(), 'revoked_at' => now(),
        ])->save();

        $this->actingAsRole('fleet_manager', ['company_id' => $this->company->id]);

        $this->assertCount(0, $this->getJson('/api/v1/fleet/locations')->json('data'));
    }

    // ---------------------------------------------------------- retention ---

    public function test_locations_older_than_the_retention_window_are_prunable(): void
    {
        config(['fip.location.retention_days' => 30]);

        DeviceLocation::create([
            'device_id' => $this->device->getKey(),
            'vehicle_id' => $this->vehicle->getKey(),
            'latitude' => 14.5, 'longitude' => 120.9,
            'recorded_at' => now()->subDays(40),
            'received_at' => now()->subDays(40),
        ]);
        DeviceLocation::create([
            'device_id' => $this->device->getKey(),
            'vehicle_id' => $this->vehicle->getKey(),
            'latitude' => 14.5, 'longitude' => 120.9,
            'recorded_at' => now()->subDays(2),
            'received_at' => now()->subDays(2),
        ]);

        $this->assertSame(1, (new DeviceLocation)->prunable()->count());
    }

    public function test_a_retention_of_zero_prunes_nothing(): void
    {
        // An unset or zeroed period must never be read as "delete everything".
        config(['fip.location.retention_days' => 0]);

        DeviceLocation::create([
            'device_id' => $this->device->getKey(),
            'vehicle_id' => $this->vehicle->getKey(),
            'latitude' => 14.5, 'longitude' => 120.9,
            'recorded_at' => now()->subYears(3),
            'received_at' => now()->subYears(3),
        ]);

        $this->assertSame(0, (new DeviceLocation)->prunable()->count());
    }

    public function test_revoking_a_device_keeps_its_history(): void
    {
        $this->seedTrack(3);
        $this->device->forceFill(['revoked_at' => now(), 'vehicle_id' => null])->save();

        $this->assertSame(
            3,
            DeviceLocation::where('device_id', $this->device->id)->count(),
            'Revocation stops future writes; it does not erase where the vehicle went.',
        );
    }
}
