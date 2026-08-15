<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Fleet\Models\Driver;
use App\Domain\Fleet\Services\FleetOverviewService;
use App\Domain\User\Models\Company;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Models\VehicleAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The fleet dashboard: what an operator sees on opening the product.
 *
 * Two things are asserted throughout. The counts must be *derived* from the
 * records that exist rather than stored, because nothing in the schema records
 * availability — a vehicle is available when it is active, not in maintenance
 * and not out on a trip, and that has to stay true as those change.
 *
 * And the whole payload is tenant-scoped. A fleet dashboard that leaked a
 * neighbouring company's plates would be worse than one that showed nothing.
 */
class FleetOverviewTest extends TestCase
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

    /** The overview for a company, read through the service. */
    private function overview(int $companyId): array
    {
        return app(FleetOverviewService::class)->forCompany($companyId);
    }

    /** The whole endpoint, over HTTP, as a client actually receives it. */
    private function dashboard(): array
    {
        $response = $this->getJson('/api/v1/fleet/dashboard');
        $response->assertStatus(200);

        return $response->json('data');
    }

    // ------------------------------------------------------------ summary ---

    public function test_an_active_vehicle_with_no_trip_counts_as_available(): void
    {
        Vehicle::factory()->forCompany($this->acme->id)->create(['status' => 'active']);
        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);

        $summary = $this->overview($this->acme->id)['summary'];

        $this->assertSame(1, $summary['total_vehicles']);
        $this->assertSame(1, $summary['available']);
        $this->assertSame(0, $summary['on_trip']);
        $this->assertSame(0, $summary['maintenance']);
    }

    public function test_a_vehicle_in_maintenance_is_not_available(): void
    {
        Vehicle::factory()->forCompany($this->acme->id)->create(['status' => 'in_maintenance']);
        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);

        $summary = $this->overview($this->acme->id)['summary'];

        $this->assertSame(1, $summary['total_vehicles']);
        $this->assertSame(0, $summary['available']);
        $this->assertSame(1, $summary['maintenance']);
    }

    public function test_an_inactive_vehicle_is_counted_but_not_available(): void
    {
        // Sold and retired vehicles still belong to the fleet on paper. They
        // are not capacity, so they must not inflate "available".
        Vehicle::factory()->forCompany($this->acme->id)->create(['status' => 'sold']);
        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);

        $summary = $this->overview($this->acme->id)['summary'];

        $this->assertSame(1, $summary['total_vehicles']);
        $this->assertSame(0, $summary['available']);
        $this->assertSame(0, $summary['maintenance']);
    }

    public function test_the_counts_are_derived_not_stored(): void
    {
        // Three vehicles, one of each state, from a single fleet read.
        Vehicle::factory()->forCompany($this->acme->id)->create(['status' => 'active']);
        Vehicle::factory()->forCompany($this->acme->id)->create(['status' => 'in_maintenance']);
        Vehicle::factory()->forCompany($this->acme->id)->create(['status' => 'inactive']);

        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);
        $summary = $this->overview($this->acme->id)['summary'];

        $this->assertSame(3, $summary['total_vehicles']);
        $this->assertSame(1, $summary['available']);
        $this->assertSame(1, $summary['maintenance']);
    }

    // ------------------------------------------------------- status table ---

    public function test_the_status_table_names_the_assigned_driver(): void
    {
        // The reason the table exists: a plate on its own does not tell an
        // operator who to ring.
        $vehicle = Vehicle::factory()->forCompany($this->acme->id)->create(['plate_number' => 'ACM 1001']);
        $driver = Driver::create([
            'company_id' => $this->acme->id,
            'first_name' => 'Marisol',
            'last_name' => 'Reyes',
        ]);
        VehicleAssignment::create([
            'vehicle_id' => $vehicle->getKey(),
            'driver_id' => $driver->getKey(),
            'assigned_at' => now(),
        ]);

        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);
        $rows = $this->overview($this->acme->id)['vehicles'];

        $this->assertCount(1, $rows);
        $this->assertSame('ACM 1001', $rows[0]['plate_number']);
        $this->assertSame('Marisol Reyes', $rows[0]['driver']['name']);
        $this->assertSame('available', $rows[0]['state']);
    }

    public function test_a_vehicle_with_no_driver_reports_null_rather_than_a_blank(): void
    {
        Vehicle::factory()->forCompany($this->acme->id)->create();
        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);

        $this->assertNull($this->overview($this->acme->id)['vehicles'][0]['driver']);
    }

    public function test_a_released_assignment_no_longer_names_a_driver(): void
    {
        $vehicle = Vehicle::factory()->forCompany($this->acme->id)->create();
        $driver = Driver::create(['company_id' => $this->acme->id, 'first_name' => 'Past', 'last_name' => 'Driver']);
        VehicleAssignment::create([
            'vehicle_id' => $vehicle->getKey(),
            'driver_id' => $driver->getKey(),
            'assigned_at' => now()->subDay(),
            'released_at' => now(),
        ]);

        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);

        $this->assertNull($this->overview($this->acme->id)['vehicles'][0]['driver']);
    }

    // ---------------------------------------------------- tenant isolation ---

    public function test_the_summary_counts_only_your_own_vehicles(): void
    {
        Vehicle::factory()->count(2)->forCompany($this->acme->id)->create(['status' => 'active']);
        Vehicle::factory()->count(5)->forCompany($this->rival->id)->create(['status' => 'active']);

        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);
        $summary = $this->overview($this->acme->id)['summary'];

        $this->assertSame(2, $summary['total_vehicles']);
        $this->assertSame(2, $summary['available']);
    }

    public function test_the_status_table_never_shows_another_tenants_plate(): void
    {
        Vehicle::factory()->forCompany($this->acme->id)->create(['plate_number' => 'ACM 1001']);
        Vehicle::factory()->forCompany($this->rival->id)->create(['plate_number' => 'RIV 9999']);

        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);
        $plates = array_column($this->overview($this->acme->id)['vehicles'], 'plate_number');

        $this->assertContains('ACM 1001', $plates);
        $this->assertNotContains('RIV 9999', $plates);
    }

    public function test_recent_activity_never_leaks_another_tenant(): void
    {
        $theirVehicle = Vehicle::factory()->forCompany($this->rival->id)->create(['plate_number' => 'RIV 9999']);
        $theirDriver = Driver::create(['company_id' => $this->rival->id, 'first_name' => 'Their', 'last_name' => 'Driver']);
        VehicleAssignment::create([
            'vehicle_id' => $theirVehicle->getKey(),
            'driver_id' => $theirDriver->getKey(),
            'assigned_at' => now(),
        ]);

        Vehicle::factory()->forCompany($this->acme->id)->create();
        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);

        $body = json_encode($this->overview($this->acme->id)['recent_activity']);

        $this->assertStringNotContainsString('RIV 9999', (string) $body);
        $this->assertStringNotContainsString('Their', (string) $body);
    }

    // ------------------------------------------------------ authorization ---

    public function test_a_driver_may_not_read_the_fleet_dashboard(): void
    {
        Vehicle::factory()->forCompany($this->acme->id)->create();
        $this->actingAsRole('driver', ['company_id' => $this->acme->id]);

        $this->getJson('/api/v1/fleet/dashboard')->assertStatus(403);
    }

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $this->getJson('/api/v1/fleet/dashboard')->assertStatus(401);
    }

    public function test_a_manager_with_no_company_is_refused(): void
    {
        // Fail closed: without a company there is no fleet to scope to, and
        // falling through would mean reading across every tenant.
        $this->actingAsRole('fleet_manager', ['company_id' => null]);

        $this->getJson('/api/v1/fleet/dashboard')->assertStatus(403);
    }

    public function test_the_no_company_refusal_names_itself(): void
    {
        /*
         * Two different 403s reach this endpoint — not entitled, and not in a
         * tenant — and a client cannot tell them apart from the status alone.
         * A platform administrator has no company by design, so without a
         * distinct code the fleet page rendered a grid of dashes that looked
         * like an outage rather than an explanation.
         */
        $this->actingAsRole('super_admin', ['company_id' => null]);

        $response = $this->getJson('/api/v1/fleet/dashboard');

        $response->assertStatus(403);
        $this->assertSame('company_required', $response->json('error.code'));
        $this->assertStringContainsString('not linked to a company', $response->json('error.message'));
    }

    public function test_lacking_the_permission_is_a_different_refusal(): void
    {
        // A driver is in a company but not entitled, so it must not surface as
        // company_required — that would send them looking for a missing tenant.
        $this->actingAsRole('driver', ['company_id' => $this->acme->id]);

        $response = $this->getJson('/api/v1/fleet/dashboard');

        $response->assertStatus(403);
        $this->assertNotSame('company_required', $response->json('error.code'));
    }

    // -------------------------------------------------- no fabricated data ---

    public function test_an_empty_fleet_reports_zeroes_rather_than_inventing_rows(): void
    {
        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);
        $data = $this->overview($this->acme->id);

        $this->assertSame(0, $data['summary']['total_vehicles']);
        $this->assertSame([], $data['vehicles']);
        $this->assertSame([], $data['maintenance']);
        $this->assertSame([], $data['recent_activity']);
    }

    public function test_the_overview_returns_the_documented_shape(): void
    {
        // The keys the fleet dashboard is specified to carry. Asserted here
        // because the merged HTTP payload cannot be exercised under SQLite.
        Vehicle::factory()->forCompany($this->acme->id)->create();
        $data = $this->overview($this->acme->id);

        foreach (['summary', 'vehicles', 'maintenance', 'recent_activity'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }

        foreach (['total_vehicles', 'available', 'on_trip', 'maintenance'] as $key) {
            $this->assertArrayHasKey($key, $data['summary']);
        }
    }

    public function test_the_overview_keys_do_not_collide_with_the_existing_payload(): void
    {
        // The bug this pins: the overview was first merged across the top level
        // with `+`, which keeps the left operand — so forFleet's `summary`,
        // `vehicles` and `maintenance` silently swallowed the overview's own,
        // and the dashboard read nulls while every service test still passed.
        $overview = array_keys($this->overview($this->acme->id));
        $existing = ['vehicles', 'drivers', 'summary', 'fraud_alerts', 'maintenance', 'utilisation'];

        $collisions = array_intersect($overview, $existing);

        $this->assertNotEmpty(
            $collisions,
            'If these no longer collide the nesting may be unnecessary — re-check the controller.',
        );
        $this->assertSame(
            ['summary', 'vehicles', 'maintenance'],
            array_values($collisions),
            'The overview must stay nested under its own key while these names clash.',
        );
    }

    public function test_the_overview_carries_no_administrative_information(): void
    {
        // The dashboard answers "what is happening with my fleet", and nothing
        // about users, tenants, plans or service health belongs in that answer.
        Vehicle::factory()->forCompany($this->acme->id)->create();
        $body = (string) json_encode($this->overview($this->acme->id));

        foreach (['subscription', 'company_count', 'total_users', 'api_health', 'database'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $body);
        }
    }

    // ------------------------------------------------------- over the wire ---

    public function test_the_endpoint_serves_the_overview_alongside_the_existing_payload(): void
    {
        /*
         * The test that could not exist. DashboardService::monthlySeries built
         * MySQL-only DATE_FORMAT SQL, so this endpoint 500'd under SQLite and
         * had no HTTP cover at all — which is how a key collision shipped that
         * every service-level test passed straight through.
         */
        Vehicle::factory()->forCompany($this->acme->id)->create(['plate_number' => 'ACM 1001']);
        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);

        $data = $this->dashboard();

        // The overview survives the merge with its own shape intact.
        $this->assertSame(1, $data['overview']['summary']['total_vehicles']);
        $this->assertSame('ACM 1001', $data['overview']['vehicles'][0]['plate_number']);

        // And the keys the fleet page already consumed are still themselves.
        foreach (['vehicles', 'drivers', 'summary', 'fraud_alerts', 'maintenance', 'utilisation'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }

        // `summary` at the top level is the expense summary, not the overview's.
        $this->assertArrayNotHasKey('total_vehicles', $data['summary']);
        $this->assertIsArray($data['monthly_series']);
    }

    public function test_the_endpoint_is_tenant_scoped_over_the_wire(): void
    {
        Vehicle::factory()->forCompany($this->acme->id)->create(['plate_number' => 'ACM 1001']);
        Vehicle::factory()->forCompany($this->rival->id)->create(['plate_number' => 'RIV 9999']);

        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);
        $body = (string) json_encode($this->dashboard());

        $this->assertStringContainsString('ACM 1001', $body);
        $this->assertStringNotContainsString('RIV 9999', $body);
    }

    public function test_the_monthly_series_runs_on_this_connection(): void
    {
        // Directly pins the portability fix: the grouped date expression has to
        // execute on whatever driver the suite is using, not only on MySQL.
        Vehicle::factory()->forCompany($this->acme->id)->create();
        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);

        $this->assertIsArray($this->dashboard()['monthly_series']);
    }
}
