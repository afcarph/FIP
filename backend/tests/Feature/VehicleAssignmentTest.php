<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Fleet\Models\Driver;
use App\Domain\User\Models\Company;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Models\VehicleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Who drives what, and who gets to decide.
 *
 * Assignment already had an endpoint. Releasing did not, so the only way to
 * take a driver off a vehicle was to assign somebody else to it — which meant
 * a vehicle could never simply be free.
 *
 * The rule worth protecting is that releasing dates the record rather than
 * deleting it. Fuel and fraud reporting read who drove what between which
 * dates, and tidying a screen must not rewrite that.
 */
class VehicleAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Company $acme;

    private Company $rival;

    private Vehicle $vehicle;

    private Driver $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        $this->acme = Company::factory()->create();
        $this->rival = Company::factory()->create();
        $this->vehicle = Vehicle::factory()->forCompany($this->acme->id)->create(['plate_number' => 'ACM 1001']);
        $this->driver = Driver::create([
            'company_id' => $this->acme->id,
            'first_name' => 'Marisol',
            'last_name' => 'Reyes',
        ]);
    }

    private function assign(?Driver $driver = null): VehicleAssignment
    {
        return VehicleAssignment::create([
            'vehicle_id' => $this->vehicle->getKey(),
            'driver_id' => ($driver ?? $this->driver)->getKey(),
            'assigned_at' => now(),
        ]);
    }

    private function release(?Vehicle $vehicle = null)
    {
        $vehicle ??= $this->vehicle;

        return $this->deleteJson("/api/v1/fleet/vehicles/{$vehicle->getKey()}/assignment");
    }

    // ------------------------------------------------------------- release ---

    public function test_a_fleet_manager_releases_the_current_driver(): void
    {
        $assignment = $this->assign();
        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);

        $this->release()->assertStatus(200)->assertJsonPath('data.released', 1);

        $this->assertNotNull($assignment->refresh()->released_at);
    }

    public function test_releasing_dates_the_record_rather_than_deleting_it(): void
    {
        // The row is the evidence that somebody drove this vehicle between two
        // dates. Fuel and fraud reporting read it.
        $assignment = $this->assign();
        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);

        $this->release()->assertStatus(200);

        $this->assertDatabaseHas('vehicle_assignments', [
            'id' => $assignment->getKey(),
            'driver_id' => $this->driver->getKey(),
        ]);
    }

    public function test_releasing_a_free_vehicle_is_not_an_error(): void
    {
        // Idempotent on purpose: a second click, or two people releasing at
        // once, should not produce a failure anybody has to interpret.
        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);

        $this->release()->assertStatus(200)->assertJsonPath('data.released', 0);
    }

    public function test_a_released_vehicle_reports_no_driver(): void
    {
        $this->assign();
        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);
        $this->release()->assertStatus(200);

        $this->assertNull($this->vehicle->fresh()->currentAssignment);
    }

    public function test_an_earlier_release_is_left_alone(): void
    {
        // Only the live assignment is closed. A historic one keeps the date it
        // was actually released on.
        $old = $this->assign();
        $old->forceFill(['released_at' => now()->subMonth()])->save();
        $live = $this->assign();

        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);
        $this->release()->assertStatus(200)->assertJsonPath('data.released', 1);

        $this->assertTrue($old->refresh()->released_at->isBefore(now()->subWeek()));
        $this->assertNotNull($live->refresh()->released_at);
    }

    // ------------------------------------------------------- authorization ---

    public function test_a_driver_may_not_release_themselves(): void
    {
        /*
         * The distinction the assign endpoint already draws. A driver passes
         * `update` on their own vehicle — they record odometer readings — so
         * the management capability is what stops them deciding who drives it.
         */
        $this->assign();
        $this->actingAsRole('driver', ['company_id' => $this->acme->id]);

        $this->release()->assertStatus(403);
    }

    public function test_a_viewer_may_not_release(): void
    {
        $this->assign();
        $this->actingAsRole('viewer', ['company_id' => $this->acme->id]);

        $this->release()->assertStatus(403);
    }

    public function test_another_tenant_may_not_release(): void
    {
        $this->assign();
        $this->actingAsRole('fleet_manager', ['company_id' => $this->rival->id]);

        $this->release()->assertStatus(403);
        $this->assertNotNull($this->vehicle->fresh()->currentAssignment);
    }

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $this->assign();

        $this->release()->assertStatus(401);
    }

    // ------------------------------------------------------------- payload ---

    public function test_the_vehicle_list_carries_the_current_driver(): void
    {
        /*
         * The assignments screen reads who is driving what straight off the
         * vehicle list. `assigned_driver` is behind whenLoaded, so it silently
         * disappears if the eager load is ever dropped from the repository —
         * and the screen would render every vehicle as unassigned rather than
         * fail. Pinned here so that change breaks a test instead.
         */
        $this->assign();
        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);

        $this->getJson('/api/v1/vehicles')
            ->assertStatus(200)
            ->assertJsonPath('data.0.assigned_driver.id', $this->driver->getKey())
            ->assertJsonPath('data.0.assigned_driver.name', 'Marisol Reyes');
    }

    public function test_both_payloads_carry_the_company_so_the_screen_can_match_them(): void
    {
        /*
         * Found on production. A platform administrator belongs to no company,
         * so their driver and vehicle lists come back unscoped — and the
         * assignment screen offered a driver from one tenant for a vehicle in
         * another. The API refused it with a 422, correctly, but the screen had
         * offered an action that could never succeed.
         *
         * Filtering needs both companies in the payload; neither was there.
         */
        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);

        $this->getJson('/api/v1/vehicles')
            ->assertStatus(200)
            ->assertJsonPath('data.0.company_id', $this->acme->id);

        $this->getJson('/api/v1/fleet/drivers')
            ->assertStatus(200)
            ->assertJsonPath('data.0.company_id', $this->acme->id);
    }

    public function test_a_cross_company_pairing_is_refused(): void
    {
        // The rule the picker now mirrors. Pinned here so the two cannot drift:
        // if this ever starts succeeding, the screen is wrong to hide the option.
        $stray = Driver::create([
            'company_id' => $this->rival->id,
            'first_name' => 'Wrong',
            'last_name' => 'Tenant',
        ]);

        $this->actingAsRole('super_admin');

        $this->postJson('/api/v1/fleet/assignments', [
            'vehicle_id' => $this->vehicle->getKey(),
            'driver_id' => $stray->getKey(),
        ])->assertStatus(422);

        $this->assertNull($this->vehicle->fresh()->currentAssignment);
    }

    public function test_a_free_vehicle_reports_a_null_driver_rather_than_omitting_it(): void
    {
        // Explicitly null, not absent: the screen distinguishes "nobody is
        // driving this" from "the field was not loaded".
        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);

        $this->getJson('/api/v1/vehicles')
            ->assertStatus(200)
            ->assertJsonPath('data.0.assigned_driver', null);
    }

    // -------------------------------------------------------------- assign ---

    public function test_assigning_replaces_the_previous_driver(): void
    {
        // Pre-existing behaviour, pinned here because the release endpoint now
        // depends on there only ever being one live assignment per vehicle.
        $first = $this->assign();
        $second = Driver::create([
            'company_id' => $this->acme->id,
            'first_name' => 'Ramon',
            'last_name' => 'Cruz',
        ]);

        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);

        $this->postJson('/api/v1/fleet/assignments', [
            'vehicle_id' => $this->vehicle->getKey(),
            'driver_id' => $second->getKey(),
        ])->assertStatus(201);

        $this->assertNotNull($first->refresh()->released_at);
        $this->assertSame($second->getKey(), $this->vehicle->fresh()->currentAssignment->driver_id);
    }
}
