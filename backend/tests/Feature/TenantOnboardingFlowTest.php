<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Fleet\Models\Driver;
use App\Domain\Pricing\Models\FuelType;
use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The whole first-tenant onboarding path, in one go.
 *
 *   Super Admin -> create company -> create its Company Admin
 *   Company Admin -> users, vehicles, drivers
 *
 * The per-stage tests prove each endpoint in isolation. This proves they
 * compose: that the company a super admin creates is the one the manager is
 * confined to, that the tier chosen at creation is the tier enforced later,
 * and that a tenant set up this way cannot see or touch a neighbouring one.
 *
 * Every step goes through HTTP as the relevant actor. Nothing is reached
 * around by writing a model directly, because reaching around is exactly how
 * a broken flow passes its own tests.
 */
class TenantOnboardingFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    public function test_a_platform_admin_can_onboard_a_tenant_that_then_runs_itself(): void
    {
        // --- Super Admin creates the tenant -----------------------------
        $this->actingAsRole('super_admin');

        $companyId = $this->postJson('/api/v1/admin/companies', [
            'name' => 'Northwind Haulage',
            'subscription_tier' => 'business',
        ])->assertStatus(201)->json('data.id');

        $this->assertNotNull($companyId);

        // --- ...and its Company Admin -----------------------------------
        $this->postJson('/api/v1/admin/users', [
            'first_name' => 'Delia',
            'last_name' => 'Santos',
            'email' => 'delia@northwind.test',
            'password' => 'S3cure-Passphrase!x9',
            'company_id' => $companyId,
            'roles' => ['company_manager'],
        ])->assertStatus(201);

        $manager = User::where('email', 'delia@northwind.test')->firstOrFail();
        $this->assertSame($companyId, $manager->company_id);
        $this->assertTrue($manager->hasRole('company_manager'));

        // --- The tenant now runs itself, with no platform help ----------
        $this->actingAs($manager, 'api');

        // A user of their own
        $this->postJson('/api/v1/admin/users', [
            'first_name' => 'Ramon',
            'last_name' => 'Cruz',
            'email' => 'ramon@northwind.test',
            'password' => 'S3cure-Passphrase!x9',
            'roles' => ['driver'],
        ])->assertStatus(201);

        // Created into their company without having to name it
        $this->assertSame($companyId, User::where('email', 'ramon@northwind.test')->value('company_id'));

        // A vehicle
        $this->postJson('/api/v1/vehicles', [
            'plate_number' => 'NWH 1234',
            'vehicle_type' => 'truck',
            'fuel_type_id' => FuelType::query()->value('id'),
            'tank_capacity' => 200,
        ])->assertStatus(201);

        $this->assertSame($companyId, Vehicle::where('plate_number', 'NWH 1234')->value('company_id'));

        // A driver
        $this->postJson('/api/v1/fleet/drivers', [
            'first_name' => 'Ramon',
            'last_name' => 'Cruz',
            'user_id' => User::where('email', 'ramon@northwind.test')->value('id'),
        ])->assertStatus(201);

        $this->assertSame($companyId, Driver::where('first_name', 'Ramon')->value('company_id'));

        // --- The tier chosen at creation is the one enforced ------------
        $report = $this->getJson("/api/v1/admin/companies/{$companyId}")
            ->assertStatus(200)
            ->json('data.subscription');

        $this->assertSame('business', $report['tier']);
        $this->assertSame(1, $report['resources']['vehicles']['used']);
        $this->assertSame(2, $report['resources']['seats']['used']);
    }

    public function test_an_onboarded_tenant_cannot_reach_its_neighbour(): void
    {
        // Two tenants created the same way, then checked against each other.
        $this->actingAsRole('super_admin');

        $mine = $this->postJson('/api/v1/admin/companies', ['name' => 'Northwind'])
            ->assertStatus(201)->json('data.id');
        $theirs = $this->postJson('/api/v1/admin/companies', ['name' => 'Southgale'])
            ->assertStatus(201)->json('data.id');

        foreach ([['delia', $mine], ['imelda', $theirs]] as [$name, $company]) {
            $this->postJson('/api/v1/admin/users', [
                'first_name' => ucfirst($name),
                'last_name' => 'Manager',
                'email' => "{$name}@example.test",
                'password' => 'S3cure-Passphrase!x9',
                'company_id' => $company,
                'roles' => ['company_manager'],
            ])->assertStatus(201);
        }

        $mineManager = User::where('email', 'delia@example.test')->firstOrFail();
        $theirManager = User::where('email', 'imelda@example.test')->firstOrFail();

        $this->actingAs($mineManager, 'api');

        // Cannot read the neighbouring tenant
        $this->getJson("/api/v1/admin/companies/{$theirs}")->assertStatus(403);

        // Cannot see their people
        $emails = array_column($this->getJson('/api/v1/admin/users?per_page=100')->json('data'), 'email');
        $this->assertNotContains('imelda@example.test', $emails);

        // Cannot edit their manager
        $this->patchJson("/api/v1/admin/users/{$theirManager->getKey()}", ['first_name' => 'Hijacked'])
            ->assertStatus(403);

        // Cannot plant a user or a driver in their company
        $this->postJson('/api/v1/admin/users', [
            'first_name' => 'Trojan', 'last_name' => 'Horse',
            'email' => 'trojan@example.test', 'password' => 'S3cure-Passphrase!x9',
            'company_id' => $theirs, 'roles' => ['driver'],
        ])->assertStatus(403);

        $this->postJson('/api/v1/fleet/drivers', [
            'first_name' => 'Trojan', 'last_name' => 'Horse', 'company_id' => $theirs,
        ])->assertStatus(403);
    }

    public function test_the_tier_set_at_onboarding_actually_binds(): void
    {
        // A company onboarded onto `free` is held to free's limits, which is
        // the point of being able to set the tier at all.
        $this->actingAsRole('super_admin');

        $companyId = $this->postJson('/api/v1/admin/companies', [
            'name' => 'Tiny Fleet',
            'subscription_tier' => 'free',
        ])->assertStatus(201)->json('data.id');

        config(['fip.subscription.tiers.free' => ['vehicles' => 1, 'seats' => 5, 'devices' => 3]]);

        $this->postJson('/api/v1/admin/users', [
            'first_name' => 'Solo', 'last_name' => 'Manager',
            'email' => 'solo@tiny.test', 'password' => 'S3cure-Passphrase!x9',
            'company_id' => $companyId, 'roles' => ['company_manager'],
        ])->assertStatus(201);

        $this->actingAs(User::where('email', 'solo@tiny.test')->firstOrFail(), 'api');

        $vehicle = fn (string $plate) => [
            'plate_number' => $plate,
            'vehicle_type' => 'van',
            'fuel_type_id' => FuelType::query()->value('id'),
            'tank_capacity' => 60,
        ];

        $this->postJson('/api/v1/vehicles', $vehicle('TNY 0001'))->assertStatus(201);
        $this->postJson('/api/v1/vehicles', $vehicle('TNY 0002'))->assertStatus(402);

        $this->assertSame(1, Vehicle::where('company_id', $companyId)->count());
    }

    public function test_onboarding_leaves_no_companyless_stragglers(): void
    {
        // The defect that started this: omitting company_id created a user in
        // no tenant while still charging a seat to one.
        $this->actingAsRole('super_admin');

        $companyId = $this->postJson('/api/v1/admin/companies', ['name' => 'Northwind'])
            ->assertStatus(201)->json('data.id');

        $this->postJson('/api/v1/admin/users', [
            'first_name' => 'Delia', 'last_name' => 'Santos',
            'email' => 'delia@northwind.test', 'password' => 'S3cure-Passphrase!x9',
            'company_id' => $companyId, 'roles' => ['company_manager'],
        ])->assertStatus(201);

        $this->actingAs(User::where('email', 'delia@northwind.test')->firstOrFail(), 'api');

        $this->postJson('/api/v1/admin/users', [
            'first_name' => 'No', 'last_name' => 'Company',
            'email' => 'straggler@northwind.test', 'password' => 'S3cure-Passphrase!x9',
            'roles' => ['driver'],
        ])->assertStatus(201);

        $this->assertSame(
            0,
            User::whereNull('company_id')->where('email', 'like', '%@northwind.test')->count(),
            'Every user created during onboarding should belong to the tenant.',
        );

        $this->assertSame(Company::find($companyId)->getKey(), $companyId);
    }
}
