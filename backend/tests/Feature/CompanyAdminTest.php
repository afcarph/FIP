<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\User\Models\Company;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Creating and administering tenants.
 *
 * Before this endpoint existed a company could only be made by editing the
 * database, so the first step of onboarding anyone had no API — and
 * subscription_tier, which every limit in the platform reads, could not be set
 * through the product that enforces it.
 *
 * The tier is the reason the authorization here matters more than it looks: a
 * tenant that could set its own tier would be setting its own bill.
 */
class CompanyAdminTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Northwind Haulage',
            'contact_email' => 'ops@northwind.test',
        ], $overrides);
    }

    // -------------------------------------------------------------- create ---

    public function test_a_platform_administrator_creates_a_tenant(): void
    {
        $this->actingAsRole('super_admin');

        $response = $this->postJson('/api/v1/admin/companies', $this->payload());

        $response->assertStatus(201);
        $this->assertSame('Northwind Haulage', $response->json('data.name'));
        $this->assertDatabaseHas('companies', ['name' => 'Northwind Haulage']);
    }

    public function test_a_company_created_without_a_tier_lands_on_the_default(): void
    {
        $this->actingAsRole('super_admin');

        $response = $this->postJson('/api/v1/admin/companies', $this->payload());

        $this->assertSame(config('fip.subscription.default_tier'), $response->json('data.subscription_tier'));
    }

    public function test_a_tier_that_does_not_exist_is_refused(): void
    {
        // The column is a plain varchar, so an unrecognised value would store
        // happily and then read back as `free` via the limit service fallback —
        // a tenant on a plan nobody sells, quietly given the smallest allowance.
        $this->actingAsRole('super_admin');

        $this->postJson('/api/v1/admin/companies', $this->payload(['subscription_tier' => 'platinum-deluxe']))
            ->assertStatus(422);

        $this->assertDatabaseMissing('companies', ['name' => 'Northwind Haulage']);
    }

    public function test_a_known_tier_is_accepted(): void
    {
        $this->actingAsRole('super_admin');

        $this->postJson('/api/v1/admin/companies', $this->payload(['subscription_tier' => 'enterprise']))
            ->assertStatus(201)
            ->assertJsonPath('data.subscription_tier', 'enterprise');
    }

    public function test_a_company_manager_may_not_create_a_tenant(): void
    {
        // Creating tenants is a platform decision. A company manager creating
        // companies would be minting customers.
        $company = Company::factory()->create();
        $this->actingAsRole('company_manager', ['company_id' => $company->id]);

        $this->postJson('/api/v1/admin/companies', $this->payload())->assertStatus(403);
    }

    public function test_a_driver_may_not_create_a_tenant(): void
    {
        $company = Company::factory()->create();
        $this->actingAsRole('driver', ['company_id' => $company->id]);

        $this->postJson('/api/v1/admin/companies', $this->payload())->assertStatus(403);
    }

    // ---------------------------------------------------------------- read ---

    public function test_a_platform_administrator_lists_every_tenant(): void
    {
        Company::factory()->count(3)->create();
        $this->actingAsRole('super_admin');

        $this->getJson('/api/v1/admin/companies')->assertStatus(200)->assertJsonCount(3, 'data');
    }

    public function test_a_company_manager_may_not_list_tenants(): void
    {
        Company::factory()->count(2)->create();
        $company = Company::factory()->create();
        $this->actingAsRole('company_manager', ['company_id' => $company->id]);

        $this->getJson('/api/v1/admin/companies')->assertStatus(403);
    }

    public function test_a_company_manager_may_read_their_own_tenant(): void
    {
        // How a manager finds out they are out of seats. Refusing this would
        // make every 402 unexplainable from inside the product.
        $company = Company::factory()->create();
        $this->actingAsRole('company_manager', ['company_id' => $company->id]);

        $this->getJson("/api/v1/admin/companies/{$company->id}")
            ->assertStatus(200)
            ->assertJsonPath('data.id', $company->id);
    }

    public function test_a_company_manager_may_not_read_another_tenant(): void
    {
        $mine = Company::factory()->create();
        $theirs = Company::factory()->create();
        $this->actingAsRole('company_manager', ['company_id' => $mine->id]);

        $this->getJson("/api/v1/admin/companies/{$theirs->id}")->assertStatus(403);
    }

    public function test_the_detail_view_reports_usage_against_the_plan(): void
    {
        $company = Company::factory()->create(['subscription_tier' => 'free']);
        Vehicle::factory()->count(2)->forCompany($company->id)->create();
        $this->actingAsRole('super_admin');

        $response = $this->getJson("/api/v1/admin/companies/{$company->id}");

        $response->assertStatus(200);
        $this->assertSame(2, $response->json('data.subscription.resources.vehicles.used'));
        $this->assertSame('free', $response->json('data.subscription.tier'));
    }

    // -------------------------------------------------------------- update ---

    public function test_a_platform_administrator_changes_the_tier(): void
    {
        $company = Company::factory()->create(['subscription_tier' => 'free']);
        $this->actingAsRole('super_admin');

        $this->patchJson("/api/v1/admin/companies/{$company->id}", ['subscription_tier' => 'business'])
            ->assertStatus(200)
            ->assertJsonPath('data.subscription_tier', 'business');

        $this->assertSame('business', $company->refresh()->subscription_tier);
    }

    public function test_a_company_manager_may_not_raise_their_own_tier(): void
    {
        // The whole reason update is platform-only: this would be a tenant
        // granting itself a bigger plan.
        $company = Company::factory()->create(['subscription_tier' => 'free']);
        $this->actingAsRole('company_manager', ['company_id' => $company->id]);

        $this->patchJson("/api/v1/admin/companies/{$company->id}", ['subscription_tier' => 'enterprise'])
            ->assertStatus(403);

        $this->assertSame('free', $company->refresh()->subscription_tier);
    }

    public function test_a_downgrade_below_current_usage_keeps_everything(): void
    {
        // Limits refuse new records without touching existing ones, so cutting
        // a tier must never remove a vehicle somebody is driving.
        $company = Company::factory()->create(['subscription_tier' => 'enterprise']);
        Vehicle::factory()->count(6)->forCompany($company->id)->create();
        $this->actingAsRole('super_admin');

        $response = $this->patchJson("/api/v1/admin/companies/{$company->id}", ['subscription_tier' => 'free']);

        $response->assertStatus(200);
        $this->assertSame(6, Vehicle::where('company_id', $company->id)->count());
        $this->assertTrue($response->json('data.subscription.resources.vehicles.over_limit'));
    }

    public function test_a_partial_update_does_not_blank_other_fields(): void
    {
        $company = Company::factory()->create(['name' => 'Original', 'contact_email' => 'keep@me.test']);
        $this->actingAsRole('super_admin');

        $this->patchJson("/api/v1/admin/companies/{$company->id}", ['name' => 'Renamed'])->assertStatus(200);

        $company->refresh();
        $this->assertSame('Renamed', $company->name);
        $this->assertSame('keep@me.test', $company->contact_email);
    }

    // ------------------------------------------------------------- absence ---

    public function test_there_is_no_delete_endpoint(): void
    {
        // Deleting a tenant would orphan its users, vehicles and devices.
        // is_active expresses "stop using this" without destroying anything.
        $company = Company::factory()->create();
        $this->actingAsRole('super_admin');

        $this->deleteJson("/api/v1/admin/companies/{$company->id}")->assertStatus(405);
    }

    public function test_deactivating_a_tenant_is_how_it_is_retired(): void
    {
        $company = Company::factory()->create(['is_active' => true]);
        $this->actingAsRole('super_admin');

        $this->patchJson("/api/v1/admin/companies/{$company->id}", ['is_active' => false])->assertStatus(200);

        $this->assertFalse($company->refresh()->is_active);
        $this->assertNull($company->deleted_at);
    }
}
