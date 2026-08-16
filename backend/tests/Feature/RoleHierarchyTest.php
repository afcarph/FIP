<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Ai\Models\FraudAlert;
use App\Domain\Fleet\Models\Driver;
use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * The core role hierarchy: Super Admin, Company Admin, Fleet Manager, Driver,
 * Viewer.
 *
 * Two things are asserted throughout. First, that each role can do its job.
 * Second — and this is the part that rots quietly — that no role gained
 * anything it was not given: `syncPermissions` replaces a role's whole set, so
 * a careless seeder edit can widen a role without anyone noticing.
 */
class RoleHierarchyTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        $this->company = Company::factory()->create();
        $this->vehicle = Vehicle::factory()->forCompany($this->company->id)->create();
    }

    private function alert(): FraudAlert
    {
        return FraudAlert::create([
            'company_id' => $this->company->id,
            'vehicle_id' => $this->vehicle->getKey(),
            'alert_type' => 'fuel_loss',
            'severity' => 'high',
            'score' => 0.8,
            'status' => 'open',
            'detected_at' => now()->subHour(),
        ]);
    }

    // ----------------------------------------------------------- viewer -----

    public function test_the_viewer_role_exists_with_exactly_the_approved_permissions(): void
    {
        $viewer = Role::where('name', 'viewer')->first();

        $this->assertNotNull($viewer, 'The viewer role must be seeded.');
        $this->assertEqualsCanonicalizing([
            'fleet.view',
            'vehicles.view',
            'fraud.view',
            'reports.view',
            'analytics.view',
            'prices.view',
            'stations.view',
            // Read-only oversight extends to trips: seeing what the fleet is
            // committed to is the same kind of question as seeing its vehicles.
            // Still no mutation — trips.manage and trips.dispatch are withheld.
            'trips.view',
        ], $viewer->permissions->pluck('name')->all());
    }

    public function test_a_viewer_can_read_the_fleet_and_its_alerts(): void
    {
        $this->actingAsRole('viewer', ['company_id' => $this->company->id]);
        $this->alert();

        $this->getJson('/api/v1/fleet')->assertStatus(200);
        $this->getJson('/api/v1/fleet/fraud-alerts')->assertStatus(200);
        $this->getJson('/api/v1/vehicles?per_page=20')->assertStatus(200);
    }

    public function test_a_viewer_cannot_write(): void
    {
        $this->actingAsRole('viewer', ['company_id' => $this->company->id]);
        $alert = $this->alert();

        // A real driver id: `exists:drivers,id` is validated before the
        // permission check, so a bogus id would 422 before authorisation ran
        // and the test would prove nothing.
        $driver = Driver::create([
            'company_id' => $this->company->id,
            'first_name' => 'Some',
            'last_name' => 'Driver',
            'status' => 'active',
        ]);

        // Read-only is the absence of write permissions, so each refusal here
        // is a different mechanism: capability, capability, policy.
        $this->postJson('/api/v1/fleet/assignments', [
            'vehicle_id' => $this->vehicle->getKey(),
            'driver_id' => $driver->getKey(),
        ])->assertStatus(403);

        $this->patchJson("/api/v1/fleet/fraud-alerts/{$alert->getKey()}", ['status' => 'dismissed'])
            ->assertStatus(403);

        $this->assertSame('open', $alert->fresh()->status);
    }

    public function test_a_viewer_cannot_see_the_driver_roster_or_any_location(): void
    {
        // Withheld deliberately: no drivers.view, no devices permission at all.
        $this->actingAsRole('viewer', ['company_id' => $this->company->id]);

        $this->getJson('/api/v1/fleet/drivers')->assertStatus(403);
        $this->getJson('/api/v1/fleet/locations')->assertStatus(403);
        $this->getJson("/api/v1/fleet/vehicles/{$this->vehicle->getKey()}/locations")
            ->assertStatus(403);
    }

    public function test_a_viewer_cannot_reach_administration(): void
    {
        $this->actingAsRole('viewer', ['company_id' => $this->company->id]);

        $this->getJson('/api/v1/admin/users')->assertStatus(403);
        $this->getJson('/api/v1/admin/settings/privacy')->assertStatus(403);
    }

    // ---------------------------------------------------- company admin -----

    public function test_a_company_admin_can_create_and_update_users(): void
    {
        $this->actingAsRole('company_manager', ['company_id' => $this->company->id]);

        $created = $this->postJson('/api/v1/admin/users', [
            'first_name' => 'New',
            'last_name' => 'Colleague',
            'email' => 'colleague@example.test',
            'password' => 'S3cure-Passphrase!x9',
            'company_id' => $this->company->id,
            'roles' => ['driver'],
        ]);

        $created->assertStatus(201);

        $this->patchJson("/api/v1/admin/users/{$created->json('data.id')}", [
            'first_name' => 'Renamed',
        ])->assertStatus(200);
    }

    public function test_a_company_admin_cannot_delete_users_or_manage_roles(): void
    {
        $this->actingAsRole('company_manager', ['company_id' => $this->company->id]);
        $victim = User::factory()->create(['company_id' => $this->company->id]);

        $this->deleteJson("/api/v1/admin/users/{$victim->getKey()}")->assertStatus(403);
        $this->putJson('/api/v1/admin/roles/1/permissions', ['permissions' => []])
            ->assertStatus(403);

        $this->assertNotNull($victim->fresh(), 'The user must survive a refused delete.');
    }

    public function test_a_company_admin_still_cannot_resolve_alerts(): void
    {
        // Approved decision: company admins view fraud, they do not close it.
        $this->actingAsRole('company_manager', ['company_id' => $this->company->id]);
        $alert = $this->alert();

        $this->patchJson("/api/v1/fleet/fraud-alerts/{$alert->getKey()}", ['status' => 'dismissed'])
            ->assertStatus(403);
    }

    // ------------------------------------------------ unchanged roles -------

    public function test_the_driver_permission_set_is_unchanged(): void
    {
        $driver = Role::where('name', 'driver')->first();

        $this->assertEqualsCanonicalizing([
            'vehicles.view', 'expenses.view', 'expenses.create',
            'devices.view', 'devices.manage',
            'maintenance.view', 'prices.view', 'stations.view', 'ai.use',
            // Reads only, and the controller narrows those to the driver's own
            // trips. Starting and closing one is granted by holding the trip,
            // not by a permission — so trips.manage and trips.dispatch, which
            // would let a driver invent or call off work, stay absent.
            'trips.view',
        ], $driver->permissions->pluck('name')->all());
    }

    public function test_the_fleet_manager_permission_set_is_unchanged(): void
    {
        $fleet = Role::where('name', 'fleet_manager')->first();
        $names = $fleet->permissions->pluck('name');

        // 27 before trips, plus trips.view, trips.manage and trips.dispatch.
        // The number is the point: it fails when a role quietly gains anything.
        $this->assertCount(30, $names);
        foreach (['fleet.manage', 'fleet.assign_drivers', 'fraud.resolve', 'devices.location.history',
            'trips.view', 'trips.manage', 'trips.dispatch'] as $p) {
            $this->assertContains($p, $names->all(), "fleet_manager must retain $p");
        }
        $this->assertNotContains('users.create', $names->all());
    }

    public function test_consumer_roles_are_untouched(): void
    {
        $this->assertCount(15, Role::where('name', 'user')->first()->permissions);
        $this->assertCount(2, Role::where('name', 'guest')->first()->permissions);
        $this->assertCount(7, Role::where('name', 'station_admin')->first()->permissions);
    }

    public function test_no_role_gained_fraud_resolve(): void
    {
        // The capability that must stay narrow.
        $holders = Role::whereHas('permissions', fn ($q) => $q->where('name', 'fraud.resolve'))
            ->pluck('name');

        $this->assertEqualsCanonicalizing(
            ['super_admin', 'system_admin', 'fleet_manager'],
            $holders->all(),
        );
    }

    public function test_user_deletion_and_role_management_stay_narrow(): void
    {
        $deleters = Role::whereHas('permissions', fn ($q) => $q->where('name', 'users.delete'))
            ->pluck('name');
        $roleManagers = Role::whereHas('permissions', fn ($q) => $q->where('name', 'roles.manage'))
            ->pluck('name');

        $this->assertEqualsCanonicalizing(['super_admin', 'system_admin'], $deleters->all());
        $this->assertEqualsCanonicalizing(['super_admin'], $roleManagers->all());
    }
}
