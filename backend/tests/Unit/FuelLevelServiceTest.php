<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Fleet\Models\VehicleFuelReading;
use App\Domain\Fleet\Services\FuelLevelService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Support\Exceptions\DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The vehicle's cached level is what every dashboard reads, and `delta_pct` is
 * what Phase 2's drop detection will scan. Both are computed once at write time
 * and trusted everywhere afterwards, so the arithmetic and the ordering rules
 * are worth pinning precisely.
 */
class FuelLevelServiceTest extends TestCase
{
    use RefreshDatabase;

    private FuelLevelService $service;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        $this->service = new FuelLevelService;
        $this->vehicle = Vehicle::factory()->create(['tank_capacity' => 50.0]);
    }

    // ------------------------------------------------------------ basics ---

    public function test_a_decrease_produces_a_negative_delta(): void
    {
        $this->service->record($this->vehicle, ['fuel_pct' => 80, 'recorded_at' => now()->subMinutes(10)]);
        $second = $this->service->record($this->vehicle, ['fuel_pct' => 62.5, 'recorded_at' => now()]);

        $this->assertSame(-17.5, $second->delta_pct);
    }

    public function test_an_increase_produces_a_positive_delta(): void
    {
        $this->service->record($this->vehicle, ['fuel_pct' => 20, 'recorded_at' => now()->subMinutes(10)]);
        $second = $this->service->record($this->vehicle, ['fuel_pct' => 95, 'recorded_at' => now()]);

        $this->assertSame(75.0, $second->delta_pct);
    }

    public function test_the_first_reading_has_no_delta(): void
    {
        $reading = $this->service->record($this->vehicle, ['fuel_pct' => 70]);

        $this->assertNull($reading->delta_pct);
    }

    public function test_litres_are_derived_from_the_tank_capacity(): void
    {
        $reading = $this->service->record($this->vehicle, ['fuel_pct' => 40]);

        // 40% of a 50 L tank.
        $this->assertSame(20.0, $reading->fuel_litres);
    }

    public function test_litres_are_null_when_the_tank_capacity_is_unknown(): void
    {
        $vehicle = Vehicle::factory()->create(['tank_capacity' => null]);

        $reading = $this->service->record($vehicle, ['fuel_pct' => 40]);

        $this->assertNull($reading->fuel_litres, 'An invented volume would read as a measurement.');
    }

    // ------------------------------------------------------ vehicle state ---

    public function test_it_updates_the_vehicle_current_state(): void
    {
        $this->service->record($this->vehicle, ['fuel_pct' => 33.5, 'recorded_at' => now()]);

        $fresh = $this->vehicle->fresh();

        $this->assertSame(33.5, $fresh->current_fuel_pct);
        $this->assertSame(16.75, $fresh->current_fuel_litres);
        $this->assertNotNull($fresh->fuel_level_at);
    }

    public function test_an_out_of_order_reading_does_not_rewind_current_state(): void
    {
        $this->service->record($this->vehicle, ['fuel_pct' => 30, 'recorded_at' => now()]);

        // A late arrival from an offline device, describing an earlier moment.
        $this->service->record($this->vehicle, ['fuel_pct' => 90, 'recorded_at' => now()->subHours(3)]);

        $this->assertSame(
            30.0,
            $this->vehicle->fresh()->current_fuel_pct,
            'The newest reading by recorded_at speaks for the present, not the last one written.',
        );
    }

    public function test_an_out_of_order_reading_is_still_preserved(): void
    {
        $this->service->record($this->vehicle, ['fuel_pct' => 30, 'recorded_at' => now()]);
        $late = $this->service->record($this->vehicle, ['fuel_pct' => 90, 'recorded_at' => now()->subHours(3)]);

        $this->assertDatabaseHas('vehicle_fuel_readings', ['id' => $late->getKey(), 'fuel_pct' => 90.0]);
        $this->assertSame(2, VehicleFuelReading::where('vehicle_id', $this->vehicle->getKey())->count());
    }

    /**
     * A late reading landing between two existing ones splits the interval its
     * successor used to span. Without restating that successor, the same loss
     * is counted twice — which Phase 2 would report as two separate events.
     */
    public function test_a_late_reading_restates_the_delta_of_its_successor(): void
    {
        $this->service->record($this->vehicle, ['fuel_pct' => 100, 'recorded_at' => now()->subMinutes(30)]);
        $last = $this->service->record($this->vehicle, ['fuel_pct' => 40, 'recorded_at' => now()]);

        $this->assertSame(-60.0, $last->delta_pct);

        $this->service->record($this->vehicle, ['fuel_pct' => 70, 'recorded_at' => now()->subMinutes(15)]);

        $this->assertSame(
            -30.0,
            $last->fresh()->delta_pct,
            'The successor now measures only the interval it actually spans.',
        );
    }

    // -------------------------------------------------------- idempotency ---

    public function test_a_duplicate_reading_is_idempotent(): void
    {
        $at = now()->subMinutes(5);

        $first = $this->service->record($this->vehicle, ['fuel_pct' => 55, 'recorded_at' => $at]);
        $replay = $this->service->record($this->vehicle, ['fuel_pct' => 55, 'recorded_at' => $at]);

        $this->assertSame($first->getKey(), $replay->getKey());
        $this->assertSame(1, VehicleFuelReading::where('vehicle_id', $this->vehicle->getKey())->count());
    }

    public function test_a_replay_does_not_overwrite_the_stored_reading(): void
    {
        $at = now()->subMinutes(5);

        $this->service->record($this->vehicle, ['fuel_pct' => 55, 'recorded_at' => $at]);
        $replay = $this->service->record($this->vehicle, ['fuel_pct' => 12, 'recorded_at' => $at]);

        $this->assertSame(55.0, $replay->fuel_pct, 'The stored reading wins; a replay is not an edit.');
    }

    public function test_the_same_moment_from_a_different_source_is_a_distinct_reading(): void
    {
        $at = now()->subMinutes(5);

        $this->service->record($this->vehicle, ['fuel_pct' => 55, 'recorded_at' => $at]);
        $this->service->record($this->vehicle, [
            'fuel_pct' => 55, 'recorded_at' => $at, 'source' => VehicleFuelReading::SOURCE_SIMULATED,
        ]);

        $this->assertSame(2, VehicleFuelReading::where('vehicle_id', $this->vehicle->getKey())->count());
    }

    // ------------------------------------------------------------ sources ---

    public function test_a_simulated_reading_is_recorded_as_simulated(): void
    {
        $reading = $this->service->record($this->vehicle, [
            'fuel_pct' => 45,
            'source' => VehicleFuelReading::SOURCE_SIMULATED,
        ]);

        $this->assertSame('simulated', $reading->source);
        $this->assertDatabaseHas('vehicle_fuel_readings', ['id' => $reading->getKey(), 'source' => 'simulated']);
    }

    public function test_a_reading_defaults_to_the_manual_source(): void
    {
        $this->assertSame('manual', $this->service->record($this->vehicle, ['fuel_pct' => 45])->source);
    }

    public function test_the_telematics_source_is_accepted_for_future_hardware(): void
    {
        $reading = $this->service->record($this->vehicle, [
            'fuel_pct' => 45,
            'source' => VehicleFuelReading::SOURCE_TELEMATICS,
        ]);

        $this->assertSame('telematics', $reading->source);
    }

    public function test_it_rejects_an_unknown_source(): void
    {
        $this->expectException(DomainException::class);

        $this->service->record($this->vehicle, ['fuel_pct' => 45, 'source' => 'guesswork']);
    }

    // --------------------------------------------------------- validation ---

    public function test_it_rejects_a_level_above_one_hundred(): void
    {
        $this->expectException(DomainException::class);

        $this->service->record($this->vehicle, ['fuel_pct' => 101]);
    }

    public function test_it_rejects_a_negative_level(): void
    {
        $this->expectException(DomainException::class);

        $this->service->record($this->vehicle, ['fuel_pct' => -1]);
    }

    public function test_it_rejects_a_reading_dated_in_the_future(): void
    {
        $this->expectException(DomainException::class);

        $this->service->record($this->vehicle, ['fuel_pct' => 50, 'recorded_at' => now()->addHour()]);
    }

    // ------------------------------------------------------------- status ---

    public function test_status_bands_follow_the_configured_thresholds(): void
    {
        config(['fip.fuel_level.low_pct' => 25.0, 'fip.fuel_level.critical_pct' => 10.0]);

        $this->assertSame('NORMAL', $this->service->statusFor(60.0));
        $this->assertSame('NORMAL', $this->service->statusFor(25.1));
        $this->assertSame('LOW', $this->service->statusFor(25.0));
        $this->assertSame('LOW', $this->service->statusFor(10.1));
        $this->assertSame('CRITICAL', $this->service->statusFor(10.0));
        $this->assertSame('CRITICAL', $this->service->statusFor(0.0));
    }

    public function test_an_unknown_level_has_no_status(): void
    {
        $this->assertNull(
            $this->service->statusFor(null),
            'A vehicle nobody has reported on is not a vehicle that is fine.',
        );
    }

    public function test_a_level_is_stale_once_past_the_configured_window(): void
    {
        config(['fip.fuel_level.stale_after_minutes' => 60]);

        $this->assertFalse($this->service->isStale(now()->subMinutes(30)));
        $this->assertTrue($this->service->isStale(now()->subMinutes(90)));
        $this->assertTrue($this->service->isStale(null));
    }

    // -------------------------------------------------------------- purge ---

    public function test_purging_a_source_leaves_other_sources_intact(): void
    {
        $this->service->record($this->vehicle, ['fuel_pct' => 90, 'recorded_at' => now()->subMinutes(20)]);
        $this->service->record($this->vehicle, [
            'fuel_pct' => 20, 'recorded_at' => now(), 'source' => VehicleFuelReading::SOURCE_SIMULATED,
        ]);

        $deleted = $this->service->purgeBySource(VehicleFuelReading::SOURCE_SIMULATED);

        $this->assertSame(1, $deleted);
        $this->assertSame(1, VehicleFuelReading::where('vehicle_id', $this->vehicle->getKey())->count());
        $this->assertSame('manual', VehicleFuelReading::first()->source);
    }

    public function test_purging_rebuilds_the_vehicle_state_from_surviving_readings(): void
    {
        $this->service->record($this->vehicle, ['fuel_pct' => 90, 'recorded_at' => now()->subMinutes(20)]);
        $this->service->record($this->vehicle, [
            'fuel_pct' => 20, 'recorded_at' => now(), 'source' => VehicleFuelReading::SOURCE_SIMULATED,
        ]);

        $this->assertSame(20.0, $this->vehicle->fresh()->current_fuel_pct);

        $this->service->purgeBySource(VehicleFuelReading::SOURCE_SIMULATED);

        $this->assertSame(
            90.0,
            $this->vehicle->fresh()->current_fuel_pct,
            'The dashboard must fall back to the newest genuine reading, not keep a purged value.',
        );
    }

    public function test_purging_every_reading_clears_the_vehicle_state(): void
    {
        $this->service->record($this->vehicle, [
            'fuel_pct' => 20, 'source' => VehicleFuelReading::SOURCE_SIMULATED,
        ]);

        $this->service->purgeBySource(VehicleFuelReading::SOURCE_SIMULATED);

        $fresh = $this->vehicle->fresh();

        $this->assertNull($fresh->current_fuel_pct);
        $this->assertNull($fresh->current_fuel_litres);
        $this->assertNull($fresh->fuel_level_at);
    }
}
