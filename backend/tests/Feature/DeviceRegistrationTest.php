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
 * Device registration decides who may report a vehicle's position, so the
 * tests that matter are the ones asserting a client cannot talk its way into
 * an association it was not granted.
 */
class DeviceRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    /** @param array<string, mixed> $overrides */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'device_uuid' => 'install-aaaa-bbbb-cccc',
            'platform' => 'android',
            'device_name' => 'Pixel 8',
            'app_version' => '1.2.0',
            'os_version' => 'Android 15',
        ], $overrides);
    }

    // ---------------------------------------------------------- registering ---

    public function test_a_driver_can_register_their_device(): void
    {
        $user = $this->actingAsRole('driver');

        $response = $this->postJson('/api/v1/devices', $this->payload());

        $this->assertApiSuccess($response, 201);
        $this->assertSame('android', $response->json('data.platform'));
        $this->assertFalse($response->json('data.is_revoked'));
        $this->assertFalse(
            $response->json('data.can_report_location'),
            'A device reports nothing until it is attached to a vehicle.',
        );

        $this->assertDatabaseHas('user_devices', [
            'user_id' => $user->id,
            'device_uuid' => 'install-aaaa-bbbb-cccc',
        ]);
    }

    public function test_registering_twice_refreshes_rather_than_duplicates(): void
    {
        $this->actingAsRole('driver');

        $this->postJson('/api/v1/devices', $this->payload());
        $second = $this->postJson('/api/v1/devices', $this->payload(['app_version' => '1.3.0']));

        $this->assertApiSuccess($second, 201);
        $this->assertSame(1, UserDevice::count(), 'A relaunch is not a new device.');
        $this->assertSame('1.3.0', UserDevice::first()->app_version);
    }

    public function test_the_same_identifier_from_another_user_is_a_separate_device(): void
    {
        $this->actingAsRole('driver');
        $this->postJson('/api/v1/devices', $this->payload());

        $this->actingAsRole('user');
        $this->postJson('/api/v1/devices', $this->payload());

        $this->assertSame(2, UserDevice::count(), 'Identity is the pair, not the identifier alone.');
    }

    public function test_registration_requires_authentication(): void
    {
        $this->postJson('/api/v1/devices', $this->payload())->assertStatus(401);
    }

    public function test_a_client_cannot_claim_a_vehicle_at_registration(): void
    {
        $user = $this->actingAsRole('driver');
        $vehicle = Vehicle::factory()->create(['owner_id' => User::factory()->create()->id]);

        $this->postJson('/api/v1/devices', $this->payload(['vehicle_id' => $vehicle->id]));

        $this->assertNull(
            UserDevice::first()->vehicle_id,
            'vehicle_id is not accepted at registration; association is a separate, authorised step.',
        );
    }

    public function test_a_client_cannot_register_a_device_for_another_user(): void
    {
        $user = $this->actingAsRole('driver');
        $other = User::factory()->create();

        $this->postJson('/api/v1/devices', $this->payload(['user_id' => $other->id]));

        $this->assertSame($user->id, UserDevice::first()->user_id, 'Ownership comes from the session.');
    }

    public function test_it_rejects_a_malformed_identifier(): void
    {
        $this->actingAsRole('driver');

        $this->assertApiValidationErrors(
            $this->postJson('/api/v1/devices', $this->payload(['device_uuid' => 'no'])),
            'device_uuid',
        );
    }

    // ---------------------------------------------------------- association ---

    public function test_an_owner_can_attach_their_device_to_their_vehicle(): void
    {
        $user = $this->actingAsRole('user');
        $vehicle = Vehicle::factory()->create(['owner_id' => $user->id]);
        $device = $this->registeredDevice($user);

        $response = $this->patchJson("/api/v1/devices/{$device->id}", ['vehicle_id' => $vehicle->id]);

        $this->assertApiSuccess($response);
        $this->assertSame($vehicle->id, $device->fresh()->vehicle_id);
        $this->assertTrue($response->json('data.can_report_location'));
    }

    public function test_a_device_cannot_be_attached_to_someone_elses_vehicle(): void
    {
        $user = $this->actingAsRole('user');
        $device = $this->registeredDevice($user);
        $foreign = Vehicle::factory()->create(['owner_id' => User::factory()->create()->id]);

        $this->patchJson("/api/v1/devices/{$device->id}", ['vehicle_id' => $foreign->id])
            ->assertStatus(403);

        $this->assertNull($device->fresh()->vehicle_id);
    }

    public function test_a_user_cannot_touch_another_users_device(): void
    {
        $owner = User::factory()->create();
        $device = $this->registeredDevice($owner);

        $this->actingAsRole('user');

        $this->patchJson("/api/v1/devices/{$device->id}", ['device_name' => 'Mine now'])->assertStatus(403);
        $this->getJson("/api/v1/devices/{$device->id}")->assertStatus(403);
    }

    public function test_only_one_active_device_reports_for_a_vehicle(): void
    {
        $user = $this->actingAsRole('user');
        $vehicle = Vehicle::factory()->create(['owner_id' => $user->id]);

        $first = $this->registeredDevice($user, 'install-first');
        $this->patchJson("/api/v1/devices/{$first->id}", ['vehicle_id' => $vehicle->id]);

        $second = $this->registeredDevice($user, 'install-second');
        $this->patchJson("/api/v1/devices/{$second->id}", ['vehicle_id' => $vehicle->id]);

        $this->assertNull($first->fresh()->vehicle_id, 'The newer registration displaces the older.');
        $this->assertSame($vehicle->id, $second->fresh()->vehicle_id);
    }

    public function test_association_changes_are_audited(): void
    {
        $user = $this->actingAsRole('user');
        $vehicle = Vehicle::factory()->create(['owner_id' => $user->id]);
        $device = $this->registeredDevice($user);

        $this->patchJson("/api/v1/devices/{$device->id}", ['vehicle_id' => $vehicle->id]);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'device_vehicle_assigned',
            'auditable_id' => $device->id,
        ]);
    }

    // ----------------------------------------------------------- revocation ---

    public function test_an_owner_can_revoke_their_device(): void
    {
        $user = $this->actingAsRole('user');
        $device = $this->registeredDevice($user);

        $response = $this->deleteJson("/api/v1/devices/{$device->id}", ['reason' => 'lost']);

        $this->assertApiSuccess($response);
        $this->assertTrue($response->json('data.is_revoked'));
        $this->assertNotNull($device->fresh()->revoked_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'device_revoked', 'auditable_id' => $device->id]);
    }

    public function test_revocation_detaches_the_vehicle_and_clears_the_push_token(): void
    {
        $user = $this->actingAsRole('user');
        $vehicle = Vehicle::factory()->create(['owner_id' => $user->id]);
        $device = $this->registeredDevice($user);
        $device->forceFill(['vehicle_id' => $vehicle->id, 'fcm_token' => 'tok'])->save();

        $this->deleteJson("/api/v1/devices/{$device->id}");

        $fresh = $device->fresh();

        $this->assertNull($fresh->vehicle_id);
        $this->assertNull($fresh->fcm_token);
    }

    public function test_a_revoked_device_cannot_re_register_itself(): void
    {
        $user = $this->actingAsRole('driver');
        $device = $this->registeredDevice($user);
        $this->deleteJson("/api/v1/devices/{$device->id}");

        $this->assertApiError(
            $this->postJson('/api/v1/devices', $this->payload(['device_uuid' => $device->device_uuid])),
            'device_revoked',
            403,
        );
    }

    public function test_a_fleet_manager_can_revoke_a_device_on_their_companys_vehicle(): void
    {
        // A handset lost with a truck is a fleet problem, and waiting for the
        // driver to revoke it is not a plan.
        $company = Company::factory()->create();
        $driver = User::factory()->create(['company_id' => $company->id]);
        $vehicle = Vehicle::factory()->forCompany($company->id)->create();

        $device = $this->registeredDevice($driver);
        $device->forceFill(['vehicle_id' => $vehicle->id])->save();

        $this->actingAsRole('fleet_manager', ['company_id' => $company->id]);

        $this->deleteJson("/api/v1/devices/{$device->id}")->assertOk();
        $this->assertNotNull($device->fresh()->revoked_at);
    }

    public function test_a_fleet_manager_cannot_rename_a_drivers_device(): void
    {
        $company = Company::factory()->create();
        $driver = User::factory()->create(['company_id' => $company->id]);
        $vehicle = Vehicle::factory()->forCompany($company->id)->create();

        $device = $this->registeredDevice($driver);
        $device->forceFill(['vehicle_id' => $vehicle->id])->save();

        $this->actingAsRole('fleet_manager', ['company_id' => $company->id]);

        $this->patchJson("/api/v1/devices/{$device->id}", ['device_name' => 'Renamed'])->assertStatus(403);
    }

    public function test_the_device_list_never_exposes_push_or_biometric_credentials(): void
    {
        $user = $this->actingAsRole('driver');
        $device = $this->registeredDevice($user);
        $device->forceFill(['fcm_token' => 'secret-token', 'biometric_key' => 'public-key'])->save();

        $body = $this->getJson('/api/v1/devices')->getContent();

        $this->assertStringNotContainsString('secret-token', $body);
        $this->assertStringNotContainsString('biometric_key', $body);
    }

    private function registeredDevice(User $user, string $uuid = 'install-aaaa-bbbb-cccc'): UserDevice
    {
        return UserDevice::create([
            'user_id' => $user->getKey(),
            'device_uuid' => $uuid,
            'platform' => 'android',
            'last_seen_at' => now(),
        ]);
    }
}
