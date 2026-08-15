<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Fleet\Models\Driver;
use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Adding and editing drivers.
 *
 * `drivers.manage` was granted to fleet_manager and company_manager but no
 * route ever consumed it, so creating a driver meant inserting a row by hand.
 *
 * The interesting part is not the CRUD, it is the foreign keys: user_id and
 * fleet_id both name records that belong to somebody. `exists:users,id` proves
 * a row is there and never that it is yours, so each is re-checked against the
 * driver's own company.
 */
class DriverAdminTest extends TestCase
{
    use RefreshDatabase;

    private Company $acme;

    private Company $rival;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        $this->acme = Company::factory()->create(['name' => 'Acme Haulage']);
        $this->rival = Company::factory()->create(['name' => 'Rival Freight']);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge(['first_name' => 'Marisol', 'last_name' => 'Reyes'], $overrides);
    }

    private function driverFor(Company $company): Driver
    {
        return Driver::create([
            'company_id' => $company->id,
            'first_name' => 'Existing',
            'last_name' => 'Driver',
        ]);
    }

    // -------------------------------------------------------------- create ---

    public function test_a_fleet_manager_adds_a_driver_to_their_own_company(): void
    {
        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);

        $response = $this->postJson('/api/v1/fleet/drivers', $this->payload());

        $response->assertStatus(201);
        $this->assertSame($this->acme->id, Driver::where('first_name', 'Marisol')->value('company_id'));
    }

    public function test_the_company_is_inferred_rather_than_left_null(): void
    {
        // A driver with no company would be invisible to the roster that is
        // scoped by company, so it would look like the create silently failed.
        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);

        $this->postJson('/api/v1/fleet/drivers', $this->payload())->assertStatus(201);

        $this->assertNotNull(Driver::where('first_name', 'Marisol')->value('company_id'));
    }

    public function test_a_fleet_manager_cannot_add_a_driver_to_another_company(): void
    {
        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);

        $this->postJson('/api/v1/fleet/drivers', $this->payload(['company_id' => $this->rival->id]))
            ->assertStatus(403);

        $this->assertDatabaseMissing('drivers', ['first_name' => 'Marisol']);
    }

    public function test_a_driver_may_not_add_drivers(): void
    {
        // drivers.manage is not a driver's permission.
        $this->actingAsRole('driver', ['company_id' => $this->acme->id]);

        $this->postJson('/api/v1/fleet/drivers', $this->payload())->assertStatus(403);
    }

    public function test_a_viewer_may_not_add_drivers(): void
    {
        $this->actingAsRole('viewer', ['company_id' => $this->acme->id]);

        $this->postJson('/api/v1/fleet/drivers', $this->payload())->assertStatus(403);
    }

    // ------------------------------------------------------- foreign keys ---

    public function test_another_companys_user_cannot_be_attached(): void
    {
        // The one that `exists:users,id` would have waved through.
        $theirs = User::factory()->create(['company_id' => $this->rival->id]);
        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);

        $this->postJson('/api/v1/fleet/drivers', $this->payload(['user_id' => $theirs->getKey()]))
            ->assertStatus(403);

        $this->assertDatabaseMissing('drivers', ['first_name' => 'Marisol']);
    }

    public function test_an_own_company_user_can_be_attached(): void
    {
        $mine = User::factory()->create(['company_id' => $this->acme->id]);
        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);

        $this->postJson('/api/v1/fleet/drivers', $this->payload(['user_id' => $mine->getKey()]))
            ->assertStatus(201);

        $this->assertSame($mine->getKey(), Driver::where('first_name', 'Marisol')->value('user_id'));
    }

    // -------------------------------------------------------------- update ---

    public function test_a_fleet_manager_edits_their_own_companys_driver(): void
    {
        $driver = $this->driverFor($this->acme);
        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);

        $this->patchJson("/api/v1/fleet/drivers/{$driver->getKey()}", ['first_name' => 'Renamed'])
            ->assertStatus(200);

        $this->assertSame('Renamed', $driver->refresh()->first_name);
    }

    public function test_knowing_an_id_does_not_grant_access_to_another_tenants_driver(): void
    {
        // Route model binding resolves by id without the tenancy scope, so the
        // policy is the only thing standing between an id and an edit.
        $theirs = $this->driverFor($this->rival);
        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);

        $this->patchJson("/api/v1/fleet/drivers/{$theirs->getKey()}", ['first_name' => 'Hijacked'])
            ->assertStatus(403);

        $this->assertSame('Existing', $theirs->refresh()->first_name);
    }

    public function test_a_driver_cannot_be_moved_between_companies(): void
    {
        // company_id is absent from the update rules entirely, so naming it is
        // ignored rather than honoured.
        $driver = $this->driverFor($this->acme);
        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);

        $this->patchJson("/api/v1/fleet/drivers/{$driver->getKey()}", ['company_id' => $this->rival->id])
            ->assertStatus(200);

        $this->assertSame($this->acme->id, $driver->refresh()->company_id);
    }

    public function test_a_partial_update_does_not_blank_other_fields(): void
    {
        $driver = $this->driverFor($this->acme);
        $driver->update(['phone' => '+63 917 000 0000']);
        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);

        $this->patchJson("/api/v1/fleet/drivers/{$driver->getKey()}", ['last_name' => 'Changed'])
            ->assertStatus(200);

        $driver->refresh();
        $this->assertSame('Changed', $driver->last_name);
        $this->assertSame('+63 917 000 0000', $driver->phone);
    }

    // ------------------------------------------------------ platform admin ---

    public function test_a_platform_administrator_may_act_across_tenants(): void
    {
        $theirs = $this->driverFor($this->rival);
        $this->actingAsRole('super_admin');

        $this->patchJson("/api/v1/fleet/drivers/{$theirs->getKey()}", ['first_name' => 'Corrected'])
            ->assertStatus(200);

        $this->assertSame('Corrected', $theirs->refresh()->first_name);
    }
}
