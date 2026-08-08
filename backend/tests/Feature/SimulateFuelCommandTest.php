<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Fleet\Models\VehicleFuelReading;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The simulator stands in for hardware that does not exist yet, so its output
 * has to be trustworthy in two directions: recognisable enough that Phase 2 can
 * be validated against it, and unmistakably separable from real data.
 */
class SimulateFuelCommandTest extends TestCase
{
    use RefreshDatabase;

    private Vehicle $vehicle;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedPlatform();

        $this->vehicle = Vehicle::factory()->create(['tank_capacity' => 60.0]);
    }

    // --------------------------------------------------- production guard ---

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('fip:simulate-fuel', ['--vehicle' => $this->vehicle->id])
            ->expectsOutputToContain('disabled in production')
            ->assertExitCode(1);

        $this->assertDatabaseCount('vehicle_fuel_readings', 0);
    }

    public function test_the_production_guard_also_blocks_a_purge(): void
    {
        $this->app->detectEnvironment(fn () => 'production');

        $this->artisan('fip:simulate-fuel', ['--purge' => true])->assertExitCode(1);
    }

    // ------------------------------------------------------------ scenarios ---

    public function test_normal_consumption_only_ever_falls(): void
    {
        $this->artisan('fip:simulate-fuel', [
            '--vehicle' => $this->vehicle->id, '--scenario' => 'normal_consumption', '--readings' => 8,
        ])->assertExitCode(0);

        $deltas = $this->deltas();

        $this->assertNotEmpty($deltas);
        foreach ($deltas as $delta) {
            $this->assertLessThanOrEqual(0, $delta, 'Ordinary driving never adds fuel.');
        }
    }

    public function test_a_refill_produces_a_positive_delta(): void
    {
        $this->artisan('fip:simulate-fuel', [
            '--vehicle' => $this->vehicle->id, '--scenario' => 'refill', '--readings' => 8, '--start' => 60,
        ])->assertExitCode(0);

        $this->assertGreaterThan(0, max($this->deltas()), 'A refill must show up as a rise.');
    }

    public function test_a_sudden_loss_produces_one_large_negative_delta(): void
    {
        $this->artisan('fip:simulate-fuel', [
            '--vehicle' => $this->vehicle->id, '--scenario' => 'sudden_loss', '--readings' => 8, '--start' => 80,
        ])->assertExitCode(0);

        // The 80% → 40% in a minute case: the whole drop lands in one interval.
        $this->assertLessThan(
            -30,
            min($this->deltas()),
            'The siphon signature is a single cliff, not a slope.',
        );
    }

    public function test_a_gradual_loss_falls_faster_than_normal_consumption(): void
    {
        $this->artisan('fip:simulate-fuel', [
            '--vehicle' => $this->vehicle->id, '--scenario' => 'gradual_loss', '--readings' => 8, '--start' => 90,
        ])->assertExitCode(0);

        $deltas = $this->deltas();

        foreach ($deltas as $delta) {
            $this->assertLessThan(0, $delta);
        }

        // Steeper than normal_consumption's 1.5 points per sample, but with no
        // single step that a cliff detector would catch.
        $this->assertLessThan(-3, min($deltas));
        $this->assertGreaterThan(-30, min($deltas));
    }

    public function test_a_sensor_anomaly_swings_in_both_directions(): void
    {
        $this->artisan('fip:simulate-fuel', [
            '--vehicle' => $this->vehicle->id, '--scenario' => 'sensor_anomaly', '--readings' => 8, '--start' => 50,
        ])->assertExitCode(0);

        $deltas = $this->deltas();

        $this->assertLessThan(0, min($deltas));
        $this->assertGreaterThan(0, max($deltas), 'A faulty gauge reads high as well as low.');
    }

    public function test_vehicle_offline_stops_reporting_early(): void
    {
        $this->artisan('fip:simulate-fuel', [
            '--vehicle' => $this->vehicle->id, '--scenario' => 'vehicle_offline', '--readings' => 12,
        ])->assertExitCode(0);

        $this->assertLessThan(
            12,
            VehicleFuelReading::count(),
            'Going offline means the readings stop, and the silence is the signal.',
        );
    }

    public function test_it_rejects_an_unknown_scenario(): void
    {
        $this->artisan('fip:simulate-fuel', [
            '--vehicle' => $this->vehicle->id, '--scenario' => 'catastrophe',
        ])->assertExitCode(1);

        $this->assertDatabaseCount('vehicle_fuel_readings', 0);
    }

    public function test_it_rejects_an_unknown_vehicle(): void
    {
        $this->artisan('fip:simulate-fuel', ['--vehicle' => 999999])->assertExitCode(1);
    }

    public function test_it_requires_a_vehicle_unless_purging(): void
    {
        $this->artisan('fip:simulate-fuel')->assertExitCode(1);
    }

    // ------------------------------------------------------------ writing ---

    public function test_every_generated_reading_is_marked_simulated(): void
    {
        $this->artisan('fip:simulate-fuel', ['--vehicle' => $this->vehicle->id, '--readings' => 6])
            ->assertExitCode(0);

        $this->assertSame(6, VehicleFuelReading::count());
        $this->assertSame(6, VehicleFuelReading::simulated()->count());
    }

    public function test_it_updates_the_vehicle_current_state(): void
    {
        $this->artisan('fip:simulate-fuel', [
            '--vehicle' => $this->vehicle->id, '--scenario' => 'normal_consumption',
            '--readings' => 5, '--start' => 50,
        ])->assertExitCode(0);

        $fresh = $this->vehicle->fresh();

        $this->assertNotNull($fresh->current_fuel_pct);
        $this->assertNotNull($fresh->fuel_level_at);
        $this->assertSame(
            VehicleFuelReading::orderByDesc('recorded_at')->first()->fuel_pct,
            $fresh->current_fuel_pct,
        );
    }

    // -------------------------------------------------------------- purge ---

    public function test_purge_removes_simulated_readings_for_one_vehicle(): void
    {
        $other = Vehicle::factory()->create();

        $this->artisan('fip:simulate-fuel', ['--vehicle' => $this->vehicle->id, '--readings' => 5]);
        $this->artisan('fip:simulate-fuel', ['--vehicle' => $other->id, '--readings' => 5]);

        $this->artisan('fip:simulate-fuel', ['--vehicle' => $this->vehicle->id, '--purge' => true])
            ->assertExitCode(0);

        $this->assertSame(0, VehicleFuelReading::where('vehicle_id', $this->vehicle->id)->count());
        $this->assertSame(5, VehicleFuelReading::where('vehicle_id', $other->id)->count());
    }

    public function test_purge_without_a_vehicle_clears_every_simulated_reading(): void
    {
        $other = Vehicle::factory()->create();

        $this->artisan('fip:simulate-fuel', ['--vehicle' => $this->vehicle->id, '--readings' => 4]);
        $this->artisan('fip:simulate-fuel', ['--vehicle' => $other->id, '--readings' => 4]);

        $this->artisan('fip:simulate-fuel', ['--purge' => true])->assertExitCode(0);

        $this->assertDatabaseCount('vehicle_fuel_readings', 0);
    }

    public function test_purge_leaves_manual_readings_untouched(): void
    {
        VehicleFuelReading::create([
            'vehicle_id' => $this->vehicle->id,
            'fuel_pct' => 77,
            'source' => VehicleFuelReading::SOURCE_MANUAL,
            'recorded_at' => now()->subDay(),
        ]);

        $this->artisan('fip:simulate-fuel', ['--vehicle' => $this->vehicle->id, '--readings' => 4]);
        $this->artisan('fip:simulate-fuel', ['--vehicle' => $this->vehicle->id, '--purge' => true]);

        $this->assertSame(1, VehicleFuelReading::count());
        $this->assertSame('manual', VehicleFuelReading::first()->source);
    }

    public function test_purge_restores_the_vehicle_state_to_real_history(): void
    {
        VehicleFuelReading::create([
            'vehicle_id' => $this->vehicle->id,
            'fuel_pct' => 77,
            'fuel_litres' => 46.2,
            'source' => VehicleFuelReading::SOURCE_MANUAL,
            'recorded_at' => now()->subDay(),
        ]);

        $this->artisan('fip:simulate-fuel', ['--vehicle' => $this->vehicle->id, '--readings' => 4, '--start' => 20]);
        $this->artisan('fip:simulate-fuel', ['--vehicle' => $this->vehicle->id, '--purge' => true]);

        $this->assertSame(77.0, $this->vehicle->fresh()->current_fuel_pct);
    }

    /** @return list<float> */
    private function deltas(): array
    {
        return VehicleFuelReading::query()
            ->whereNotNull('delta_pct')
            ->orderBy('recorded_at')
            ->pluck('delta_pct')
            ->map(static fn ($d) => (float) $d)
            ->all();
    }
}
