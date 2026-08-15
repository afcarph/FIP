<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Expense\Models\Trip;
use App\Domain\Fleet\Models\Driver;
use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tymon\JWTAuth\JWT;

/**
 * Planning a trip, sending it out, and closing it.
 *
 * The lifecycle is the whole feature, so most of these are about *when* a
 * thing may happen rather than what it writes. Two rules carry the most
 * weight:
 *
 *   A cancelled trip can never have started. Cancellation is unreachable from
 *   IN_PROGRESS, which is what keeps the fleet dashboard's "on trip" count —
 *   started and not ended — from counting abandoned work.
 *
 *   A trip never touches a VehicleAssignment. Assignment is stewardship of a
 *   vehicle; a trip is a job. Conflating them would silently move who is
 *   accountable for a vehicle every time somebody planned a delivery.
 */
class TripDispatchTest extends TestCase
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

        $this->vehicle = Vehicle::factory()->forCompany($this->acme->id)->create([
            'plate_number' => 'ACM 1001',
            'status' => 'active',
        ]);

        $this->driver = $this->driverIn($this->acme, 'Marisol', 'Reyes');

        $this->actingAsRole('fleet_manager', ['company_id' => $this->acme->id]);
    }

    private function driverIn(Company $company, string $first, string $last): Driver
    {
        $driver = new Driver;
        $driver->forceFill([
            'company_id' => $company->id,
            'first_name' => $first,
            'last_name' => $last,
            'status' => 'active',
        ])->save();

        return $driver;
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'vehicle_id' => $this->vehicle->getKey(),
            'driver_id' => $this->driver->getKey(),
            'origin_label' => 'Manila',
            'destination_label' => 'Batangas',
            'purpose' => 'Delivery',
        ], $overrides);
    }

    private function acmeUserId(): int
    {
        return User::where('company_id', $this->acme->id)->value('id');
    }

    private function tripIn(Company $company, array $state = []): Trip
    {
        $vehicle = Vehicle::factory()->forCompany($company->id)->create(['status' => 'active']);
        $driver = $this->driverIn($company, 'Local', 'Driver');

        $trip = new Trip;
        $trip->forceFill(array_merge([
            'company_id' => $company->id,
            'vehicle_id' => $vehicle->getKey(),
            'driver_id' => $driver->getKey(),
            'reference_no' => 'TRP-TEST-'.uniqid(),
            'status' => Trip::STATUS_DRAFT,
            'origin_label' => 'A',
            'destination_label' => 'B',
        ], $state))->save();

        return $trip;
    }

    // ------------------------------------------------------------ creation ---

    public function test_a_fleet_manager_plans_a_trip(): void
    {
        $response = $this->postJson('/api/v1/fleet/trips', $this->payload())->assertStatus(201);

        $response->assertJsonPath('data.status', Trip::STATUS_DRAFT)
            ->assertJsonPath('data.vehicle.plate_number', 'ACM 1001')
            ->assertJsonPath('data.driver.name', 'Marisol Reyes')
            ->assertJsonPath('data.origin', 'Manila');

        // Taken from the vehicle, never from the request.
        $this->assertSame($this->acme->id, Trip::first()->company_id);
    }

    public function test_a_trip_is_created_in_draft_not_dispatched(): void
    {
        // Planning and sending out are separate acts; creating one must not
        // put a vehicle on the road.
        $this->postJson('/api/v1/fleet/trips', $this->payload())->assertStatus(201);

        $trip = Trip::first();
        $this->assertSame(Trip::STATUS_DRAFT, $trip->status);
        $this->assertNull($trip->dispatched_at);
        $this->assertNull($trip->started_at);
    }

    public function test_a_trip_gets_a_readable_reference(): void
    {
        $reference = $this->postJson('/api/v1/fleet/trips', $this->payload())->json('data.reference_no');

        $this->assertMatchesRegularExpression('/^TRP-\d{4}-\d{5}$/', $reference);
    }

    public function test_another_companys_vehicle_is_refused(): void
    {
        $theirs = Vehicle::factory()->forCompany($this->rival->id)->create(['status' => 'active']);

        $this->postJson('/api/v1/fleet/trips', $this->payload(['vehicle_id' => $theirs->getKey()]))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'vehicle_not_found');

        $this->assertDatabaseCount('trips', 0);
    }

    public function test_another_companys_driver_is_refused(): void
    {
        $theirs = $this->driverIn($this->rival, 'Rival', 'Driver');

        $this->postJson('/api/v1/fleet/trips', $this->payload(['driver_id' => $theirs->getKey()]))
            ->assertStatus(404)
            ->assertJsonPath('error.code', 'driver_not_found');

        $this->assertDatabaseCount('trips', 0);
    }

    public function test_a_vehicle_that_is_not_active_is_refused(): void
    {
        $this->vehicle->forceFill(['status' => 'inactive'])->save();

        $this->postJson('/api/v1/fleet/trips', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'vehicle_unavailable');
    }

    public function test_a_suspended_driver_is_refused(): void
    {
        $this->driver->forceFill(['status' => 'suspended'])->save();

        $this->postJson('/api/v1/fleet/trips', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'driver_unavailable');
    }

    public function test_a_vehicle_already_on_a_trip_is_refused(): void
    {
        $this->postJson('/api/v1/fleet/trips', $this->payload())->assertStatus(201);

        $second = $this->driverIn($this->acme, 'Ramon', 'Cruz');

        $this->postJson('/api/v1/fleet/trips', $this->payload(['driver_id' => $second->getKey()]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'vehicle_on_trip');
    }

    public function test_a_driver_already_on_a_trip_is_refused(): void
    {
        $this->postJson('/api/v1/fleet/trips', $this->payload())->assertStatus(201);

        $second = Vehicle::factory()->forCompany($this->acme->id)->create(['status' => 'active']);

        $this->postJson('/api/v1/fleet/trips', $this->payload(['vehicle_id' => $second->getKey()]))
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'driver_on_trip');
    }

    public function test_a_completed_trip_frees_the_vehicle_and_driver(): void
    {
        // The other half of the clash rule: terminal trips must release their
        // pair, or a fleet would seize up after one job each.
        $id = $this->postJson('/api/v1/fleet/trips', $this->payload())->json('data.id');
        $this->postJson("/api/v1/fleet/trips/{$id}/dispatch")->assertStatus(200);
        $this->postJson("/api/v1/fleet/trips/{$id}/start")->assertStatus(200);
        $this->postJson("/api/v1/fleet/trips/{$id}/complete")->assertStatus(200);

        $this->postJson('/api/v1/fleet/trips', $this->payload())->assertStatus(201);
    }

    // ------------------------------------------------------------ dispatch ---

    public function test_a_draft_can_be_dispatched(): void
    {
        $id = $this->postJson('/api/v1/fleet/trips', $this->payload())->json('data.id');

        $this->postJson("/api/v1/fleet/trips/{$id}/dispatch")
            ->assertStatus(200)
            ->assertJsonPath('data.status', Trip::STATUS_DISPATCHED);

        $this->assertNotNull(Trip::find($id)->dispatched_at);
    }

    public function test_dispatching_does_not_start_the_trip(): void
    {
        $id = $this->postJson('/api/v1/fleet/trips', $this->payload())->json('data.id');
        $this->postJson("/api/v1/fleet/trips/{$id}/dispatch")->assertStatus(200);

        $this->assertNull(Trip::find($id)->started_at);
    }

    public function test_a_dispatched_trip_cannot_be_dispatched_again(): void
    {
        $id = $this->postJson('/api/v1/fleet/trips', $this->payload())->json('data.id');
        $this->postJson("/api/v1/fleet/trips/{$id}/dispatch")->assertStatus(200);

        $this->postJson("/api/v1/fleet/trips/{$id}/dispatch")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_trip_transition');
    }

    public function test_a_vehicle_in_maintenance_cannot_be_dispatched(): void
    {
        /*
         * Checked at dispatch and not at creation on purpose. A vehicle booked
         * into the workshop next week can still have trips planned around it;
         * what must not happen is sending out one that is in there today.
         */
        $id = $this->postJson('/api/v1/fleet/trips', $this->payload())->json('data.id');
        $this->vehicle->forceFill(['status' => 'in_maintenance'])->save();

        $this->postJson("/api/v1/fleet/trips/{$id}/dispatch")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'vehicle_unavailable');

        $this->assertSame(Trip::STATUS_DRAFT, Trip::find($id)->status);
    }

    // --------------------------------------------------------------- start ---

    public function test_a_dispatched_trip_starts_and_records_the_time(): void
    {
        $id = $this->postJson('/api/v1/fleet/trips', $this->payload())->json('data.id');
        $this->postJson("/api/v1/fleet/trips/{$id}/dispatch")->assertStatus(200);

        $this->postJson("/api/v1/fleet/trips/{$id}/start", ['odometer_start' => 10_000])
            ->assertStatus(200)
            ->assertJsonPath('data.status', Trip::STATUS_IN_PROGRESS)
            ->assertJsonPath('data.odometer.start', 10_000);

        $this->assertNotNull(Trip::find($id)->started_at);
    }

    public function test_a_draft_cannot_start(): void
    {
        $id = $this->postJson('/api/v1/fleet/trips', $this->payload())->json('data.id');

        $this->postJson("/api/v1/fleet/trips/{$id}/start")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_trip_transition');
    }

    public function test_a_completed_trip_cannot_start_again(): void
    {
        $trip = $this->tripIn($this->acme, ['status' => Trip::STATUS_COMPLETED, 'ended_at' => now()]);

        $this->postJson("/api/v1/fleet/trips/{$trip->getKey()}/start")->assertStatus(422);
    }

    // ------------------------------------------------------------ complete ---

    public function test_an_in_progress_trip_completes(): void
    {
        $trip = $this->tripIn($this->acme, [
            'status' => Trip::STATUS_IN_PROGRESS,
            'started_at' => now()->subHour(),
            'odometer_start' => 10_000,
        ]);

        $this->postJson("/api/v1/fleet/trips/{$trip->getKey()}/complete", ['odometer_end' => 10_120])
            ->assertStatus(200)
            ->assertJsonPath('data.status', Trip::STATUS_COMPLETED)
            // Cast to float on the model, so the payload carries 120.0.
            ->assertJsonPath('data.distance_km', 120.0);

        $this->assertNotNull($trip->fresh()->ended_at);
    }

    public function test_a_closing_odometer_below_the_opening_one_is_refused(): void
    {
        $trip = $this->tripIn($this->acme, [
            'status' => Trip::STATUS_IN_PROGRESS,
            'started_at' => now()->subHour(),
            'odometer_start' => 10_000,
        ]);

        $this->postJson("/api/v1/fleet/trips/{$trip->getKey()}/complete", ['odometer_end' => 9_900])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'odometer_decreased');

        $this->assertSame(Trip::STATUS_IN_PROGRESS, $trip->fresh()->status);
    }

    public function test_a_draft_cannot_be_completed(): void
    {
        $trip = $this->tripIn($this->acme);

        $this->postJson("/api/v1/fleet/trips/{$trip->getKey()}/complete")->assertStatus(422);
    }

    public function test_a_dispatched_trip_cannot_be_completed_without_starting(): void
    {
        $trip = $this->tripIn($this->acme, ['status' => Trip::STATUS_DISPATCHED, 'dispatched_at' => now()]);

        $this->postJson("/api/v1/fleet/trips/{$trip->getKey()}/complete")->assertStatus(422);
    }

    // -------------------------------------------------------------- cancel ---

    public function test_a_draft_can_be_cancelled_with_a_reason(): void
    {
        $trip = $this->tripIn($this->acme);

        $this->postJson("/api/v1/fleet/trips/{$trip->getKey()}/cancel", ['reason' => 'Customer postponed'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', Trip::STATUS_CANCELLED)
            ->assertJsonPath('data.cancellation_reason', 'Customer postponed');
    }

    public function test_cancelling_requires_a_reason(): void
    {
        $trip = $this->tripIn($this->acme);

        $this->postJson("/api/v1/fleet/trips/{$trip->getKey()}/cancel", [])->assertStatus(422);
    }

    public function test_a_dispatched_trip_can_be_cancelled(): void
    {
        $trip = $this->tripIn($this->acme, ['status' => Trip::STATUS_DISPATCHED, 'dispatched_at' => now()]);

        $this->postJson("/api/v1/fleet/trips/{$trip->getKey()}/cancel", ['reason' => 'Vehicle needed elsewhere'])
            ->assertStatus(200);
    }

    public function test_an_in_progress_trip_cannot_be_cancelled(): void
    {
        /*
         * The rule the dashboard depends on. "On trip" is a row that started
         * and has not ended, so if a started trip could be cancelled it would
         * keep its started_at and be counted as on the road forever. A trip
         * that went wrong is completed with notes instead.
         */
        $trip = $this->tripIn($this->acme, [
            'status' => Trip::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);

        $this->postJson("/api/v1/fleet/trips/{$trip->getKey()}/cancel", ['reason' => 'Changed my mind'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_trip_transition');
    }

    public function test_a_completed_trip_cannot_be_cancelled(): void
    {
        $trip = $this->tripIn($this->acme, ['status' => Trip::STATUS_COMPLETED, 'ended_at' => now()]);

        $this->postJson("/api/v1/fleet/trips/{$trip->getKey()}/cancel", ['reason' => 'Too late'])
            ->assertStatus(422);
    }

    public function test_a_cancelled_trip_cannot_be_cancelled_again(): void
    {
        $trip = $this->tripIn($this->acme, ['status' => Trip::STATUS_CANCELLED, 'cancelled_at' => now()]);

        $this->postJson("/api/v1/fleet/trips/{$trip->getKey()}/cancel", ['reason' => 'Again'])->assertStatus(422);
    }

    public function test_a_cancelled_trip_is_kept_not_deleted(): void
    {
        $trip = $this->tripIn($this->acme);

        $this->postJson("/api/v1/fleet/trips/{$trip->getKey()}/cancel", ['reason' => 'Customer postponed'])
            ->assertStatus(200);

        $this->assertDatabaseHas('trips', ['id' => $trip->getKey(), 'status' => Trip::STATUS_CANCELLED]);
        $this->assertNull($trip->fresh()->deleted_at);
    }

    // ----------------------------------------------------- tenant isolation ---

    public function test_another_tenants_trip_is_not_listed(): void
    {
        $this->tripIn($this->rival);
        $this->tripIn($this->acme);

        $this->getJson('/api/v1/fleet/trips')->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_another_tenants_trip_cannot_be_read(): void
    {
        $theirs = $this->tripIn($this->rival);

        $this->getJson("/api/v1/fleet/trips/{$theirs->getKey()}")->assertStatus(403);
    }

    public function test_another_tenants_trip_cannot_be_dispatched(): void
    {
        $theirs = $this->tripIn($this->rival);

        $this->postJson("/api/v1/fleet/trips/{$theirs->getKey()}/dispatch")->assertStatus(403);
        $this->assertSame(Trip::STATUS_DRAFT, $theirs->fresh()->status);
    }

    public function test_another_tenants_trip_cannot_be_started_or_completed(): void
    {
        $theirs = $this->tripIn($this->rival, [
            'status' => Trip::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);

        $this->postJson("/api/v1/fleet/trips/{$theirs->getKey()}/start")->assertStatus(403);
        $this->postJson("/api/v1/fleet/trips/{$theirs->getKey()}/complete")->assertStatus(403);
    }

    public function test_another_tenants_trip_cannot_be_cancelled(): void
    {
        $theirs = $this->tripIn($this->rival);

        $this->postJson("/api/v1/fleet/trips/{$theirs->getKey()}/cancel", ['reason' => 'Not mine'])
            ->assertStatus(403);

        $this->assertSame(Trip::STATUS_DRAFT, $theirs->fresh()->status);
    }

    public function test_the_summary_counts_only_your_own_trips(): void
    {
        $this->tripIn($this->rival);
        $this->tripIn($this->acme);

        $this->getJson('/api/v1/fleet/trips/summary')
            ->assertStatus(200)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.draft', 1);
    }

    // ------------------------------------------------------- authorization ---

    public function test_a_viewer_may_read_but_not_plan(): void
    {
        $this->actingAsRole('viewer', ['company_id' => $this->acme->id]);

        $this->getJson('/api/v1/fleet/trips')->assertStatus(200);
        $this->postJson('/api/v1/fleet/trips', $this->payload())->assertStatus(403);
    }

    public function test_a_viewer_may_not_dispatch(): void
    {
        $trip = $this->tripIn($this->acme);
        $this->actingAsRole('viewer', ['company_id' => $this->acme->id]);

        $this->postJson("/api/v1/fleet/trips/{$trip->getKey()}/dispatch")->assertStatus(403);
    }

    public function test_a_driver_has_no_trip_access_yet(): void
    {
        // Deliberate for this phase: driver-facing trip actions belong with the
        // mobile flow, and half a permission is worse than none.
        $this->actingAsRole('driver', ['company_id' => $this->acme->id]);

        $this->getJson('/api/v1/fleet/trips')->assertStatus(403);
    }

    public function test_an_unauthenticated_request_is_refused(): void
    {
        $trip = $this->tripIn($this->acme);
        $this->withoutHeader('Authorization');
        $this->app['auth']->forgetGuards();
        $this->app->make(JWT::class)->unsetToken();

        $this->getJson("/api/v1/fleet/trips/{$trip->getKey()}")->assertStatus(401);
    }

    // ------------------------------------------------------- fleet overview ---

    public function test_an_in_progress_trip_shows_the_vehicle_as_on_trip(): void
    {
        // The dashboard derives availability from trips, so the lifecycle and
        // the overview have to agree about what "out" means.
        $this->tripIn($this->acme, [
            'status' => Trip::STATUS_IN_PROGRESS,
            'started_at' => now(),
        ]);

        $overview = $this->getJson('/api/v1/fleet/dashboard')->assertStatus(200)->json('data.overview');

        $this->assertSame(1, $overview['summary']['on_trip']);
    }

    public function test_a_draft_or_dispatched_trip_does_not_occupy_the_vehicle(): void
    {
        // Planning work does not take a vehicle off the road; only starting it
        // does. A dispatcher filling tomorrow's schedule must not make the
        // fleet look fully committed today.
        $this->tripIn($this->acme, ['status' => Trip::STATUS_DISPATCHED, 'dispatched_at' => now()]);

        $overview = $this->getJson('/api/v1/fleet/dashboard')->assertStatus(200)->json('data.overview');

        $this->assertSame(0, $overview['summary']['on_trip']);
    }

    public function test_a_cancelled_trip_never_counts_as_on_trip(): void
    {
        /*
         * Belt-and-braces for a rule the state machine already guarantees. A
         * cancelled row is written here with a started_at it could never
         * legitimately have, to prove the dashboard would still not count it.
         */
        $this->tripIn($this->acme, [
            'status' => Trip::STATUS_CANCELLED,
            'started_at' => now(),
            'cancelled_at' => now(),
        ]);

        $overview = $this->getJson('/api/v1/fleet/dashboard')->assertStatus(200)->json('data.overview');

        $this->assertSame(0, $overview['summary']['on_trip']);
    }

    // ----------------------------------------------------------- odometer ---

    public function test_starting_and_completing_record_odometer_readings(): void
    {
        /*
         * The gap this closes. Trips used to keep their readings to themselves,
         * so a fleet that logged fuel without an odometer had no odometer
         * history at all — and the vehicle's current reading, which maintenance
         * schedules distance-based services against, never moved.
         */
        $id = $this->postJson('/api/v1/fleet/trips', $this->payload())->json('data.id');
        $this->postJson("/api/v1/fleet/trips/{$id}/dispatch")->assertStatus(200);
        $this->postJson("/api/v1/fleet/trips/{$id}/start", ['odometer_start' => 20_000])->assertStatus(200);
        $this->postJson("/api/v1/fleet/trips/{$id}/complete", ['odometer_end' => 20_150])->assertStatus(200);

        $this->assertDatabaseHas('odometer_readings', [
            'vehicle_id' => $this->vehicle->getKey(),
            'reading' => 20_000,
            'source' => 'trip_start',
        ]);
        $this->assertDatabaseHas('odometer_readings', [
            'vehicle_id' => $this->vehicle->getKey(),
            'reading' => 20_150,
            'source' => 'trip_end',
        ]);
    }

    public function test_the_vehicles_odometer_advances_with_the_trip(): void
    {
        // Pinned below the trip's readings: the factory seeds a random one, and
        // a reading under it would be correctly refused by the monotonic guard.
        $this->vehicle->forceFill(['current_odometer' => 1_000])->save();

        $id = $this->postJson('/api/v1/fleet/trips', $this->payload())->json('data.id');
        $this->postJson("/api/v1/fleet/trips/{$id}/dispatch")->assertStatus(200);
        $this->postJson("/api/v1/fleet/trips/{$id}/start", ['odometer_start' => 30_000])->assertStatus(200);
        $this->postJson("/api/v1/fleet/trips/{$id}/complete", ['odometer_end' => 30_400])->assertStatus(200);

        $this->assertSame(30_400.0, (float) $this->vehicle->fresh()->current_odometer);
    }

    public function test_the_odometer_only_ever_advances(): void
    {
        /*
         * The reason two writers on this column are safe. Fuel logging advances
         * it monotonically and so does this; a lower reading from either is
         * kept as history but never moves the vehicle backwards.
         */
        $this->vehicle->forceFill(['current_odometer' => 90_000])->save();

        $id = $this->postJson('/api/v1/fleet/trips', $this->payload())->json('data.id');
        $this->postJson("/api/v1/fleet/trips/{$id}/dispatch")->assertStatus(200);
        $this->postJson("/api/v1/fleet/trips/{$id}/start", ['odometer_start' => 10_000])->assertStatus(200);

        $this->assertSame(90_000.0, (float) $this->vehicle->fresh()->current_odometer);
        $this->assertDatabaseHas('odometer_readings', ['reading' => 10_000, 'source' => 'trip_start']);
    }

    public function test_a_trip_without_readings_writes_no_odometer_rows(): void
    {
        // Optional means optional: skipping the readings must not invent them.
        $id = $this->postJson('/api/v1/fleet/trips', $this->payload())->json('data.id');
        $this->postJson("/api/v1/fleet/trips/{$id}/dispatch")->assertStatus(200);
        $this->postJson("/api/v1/fleet/trips/{$id}/start")->assertStatus(200);
        $this->postJson("/api/v1/fleet/trips/{$id}/complete")->assertStatus(200);

        $this->assertDatabaseCount('odometer_readings', 0);
    }

    // ------------------------------------------------ distance from trips ---

    public function test_completed_trips_report_the_distance_they_recorded(): void
    {
        $this->tripIn($this->acme, [
            'vehicle_id' => $this->vehicle->getKey(),
            'status' => Trip::STATUS_COMPLETED,
            'ended_at' => now()->subDay(),
            'distance_km' => 400,
        ]);

        $this->getJson("/api/v1/vehicles/{$this->vehicle->getKey()}/efficiency")
            ->assertStatus(200)
            ->assertJsonPath('data.from_trips.distance_km', 400.0)
            ->assertJsonPath('data.from_trips.trips', 1);
    }

    public function test_trips_do_not_report_a_fuel_economy_figure(): void
    {
        /*
         * Distance over litres-bought looked reasonable and gave 0.33 km/L on
         * the first real vehicle it met — 368 litres against 120 km, because
         * almost none of that vehicle's driving had been recorded as a trip.
         * The arithmetic was right and the number was a lie. Pinned here so
         * nobody reintroduces it without confronting the coverage problem.
         */
        $this->tripIn($this->acme, [
            'vehicle_id' => $this->vehicle->getKey(),
            'status' => Trip::STATUS_COMPLETED,
            'ended_at' => now()->subDay(),
            'distance_km' => 120,
        ]);

        $payload = $this->getJson("/api/v1/vehicles/{$this->vehicle->getKey()}/efficiency")
            ->assertStatus(200)
            ->json('data.from_trips');

        $this->assertArrayNotHasKey('km_per_litre', $payload);
        $this->assertArrayNotHasKey('litres', $payload);
    }

    public function test_the_distance_is_absent_when_nothing_has_been_driven(): void
    {
        // Null rather than zero: no completed trips is no answer.
        $this->getJson("/api/v1/vehicles/{$this->vehicle->getKey()}/efficiency")
            ->assertStatus(200)
            ->assertJsonPath('data.from_trips', null);
    }

    public function test_the_tank_to_tank_average_is_left_alone(): void
    {
        // The measurement stays the measurement. Folding trip distance into
        // avg_km_per_litre would silently redefine a number the dashboard,
        // reports and route estimates all already read.
        $this->vehicle->forceFill(['avg_km_per_litre' => 12.5])->save();

        $this->tripIn($this->acme, [
            'vehicle_id' => $this->vehicle->getKey(),
            'status' => Trip::STATUS_COMPLETED,
            'ended_at' => now()->subDay(),
            'distance_km' => 400,
        ]);

        $this->getJson("/api/v1/vehicles/{$this->vehicle->getKey()}/efficiency")
            ->assertStatus(200)
            ->assertJsonPath('data.avg_km_per_litre', 12.5);
    }

    // ------------------------------------------------------------ payload ---

    public function test_the_payload_advertises_the_transitions_the_server_allows(): void
    {
        // The screen renders its buttons from this, so it cannot offer a move
        // the server would refuse.
        $trip = $this->tripIn($this->acme, ['status' => Trip::STATUS_IN_PROGRESS, 'started_at' => now()]);

        $this->getJson("/api/v1/fleet/trips/{$trip->getKey()}")
            ->assertStatus(200)
            ->assertJsonPath('data.can', [Trip::STATUS_COMPLETED]);
    }

    public function test_a_terminal_trip_advertises_no_transitions(): void
    {
        $trip = $this->tripIn($this->acme, ['status' => Trip::STATUS_COMPLETED, 'ended_at' => now()]);

        $this->getJson("/api/v1/fleet/trips/{$trip->getKey()}")
            ->assertStatus(200)
            ->assertJsonPath('data.can', []);
    }
}
