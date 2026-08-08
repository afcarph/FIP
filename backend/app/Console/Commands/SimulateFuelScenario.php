<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Fleet\Models\VehicleFuelReading;
use App\Domain\Fleet\Services\FuelLevelService;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Console\Command;

/**
 * Generate fuel telemetry for a vehicle without any hardware.
 *
 * There are no fuel sensors yet, so the only way to see how the platform
 * behaves under a real pattern — and, in Phase 2, whether detection fires when
 * it should — is to produce the pattern deliberately.
 *
 * Readings are written through FuelLevelService rather than inserted directly,
 * following DemoDataSeeder's precedent of generating data through the real
 * services. A simulator that bypassed the service would exercise none of the
 * ordering, delta or state-cache logic, and so would prove nothing about the
 * code that runs in production.
 *
 * Every row is stamped `source = simulated` and can be removed with --purge.
 *
 *   php artisan fip:simulate-fuel --vehicle=3 --scenario=sudden_loss
 *   php artisan fip:simulate-fuel --vehicle=3 --purge
 */
class SimulateFuelScenario extends Command
{
    protected $signature = 'fip:simulate-fuel
                            {--vehicle= : Vehicle id, or omit with --purge to clear every vehicle}
                            {--scenario=normal_consumption : One of '.self::SCENARIO_LIST.'}
                            {--minutes=60 : Wall-clock span the readings cover}
                            {--readings=12 : How many samples to generate}
                            {--start=80 : Starting tank level, as a percentage}
                            {--purge : Delete simulated readings instead of generating them}';

    protected $description = 'Generate or purge simulated fuel telemetry for a vehicle';

    private const SCENARIO_LIST = 'normal_consumption, refill, sudden_loss, gradual_loss, sensor_anomaly, vehicle_offline';

    /** @var list<string> */
    private const SCENARIOS = [
        'normal_consumption',
        'refill',
        'sudden_loss',
        'gradual_loss',
        'sensor_anomaly',
        'vehicle_offline',
    ];

    public function handle(FuelLevelService $fuelLevels): int
    {
        if (! $this->guardEnvironment()) {
            return self::FAILURE;
        }

        $vehicle = $this->resolveVehicle();

        if ($vehicle === false) {
            return self::FAILURE;
        }

        if ($this->option('purge')) {
            return $this->purge($fuelLevels, $vehicle);
        }

        if ($vehicle === null) {
            $this->error('A --vehicle is required unless you are purging.');

            return self::FAILURE;
        }

        $scenario = (string) $this->option('scenario');

        if (! in_array($scenario, self::SCENARIOS, true)) {
            $this->error("Unknown scenario [{$scenario}]. Expected one of: ".self::SCENARIO_LIST.'.');

            return self::FAILURE;
        }

        return $this->generate($fuelLevels, $vehicle, $scenario);
    }

    /**
     * Simulated data must never reach production. There is no --force: an
     * override exists to be used at 2am by someone who is certain, and the
     * whole point of the flag is that they should not be.
     */
    private function guardEnvironment(): bool
    {
        if (app()->environment('production')) {
            $this->error('fip:simulate-fuel is disabled in production.');
            $this->line('Simulated telemetry would be indistinguishable from a real fuel loss to anyone reading the dashboard.');

            return false;
        }

        return true;
    }

    /** @return Vehicle|null|false the vehicle, null for "all", or false on error */
    private function resolveVehicle(): Vehicle|null|false
    {
        $id = $this->option('vehicle');

        if ($id === null) {
            return null;
        }

        $vehicle = Vehicle::find((int) $id);

        if ($vehicle === null) {
            $this->error("No vehicle with id [{$id}].");

            return false;
        }

        return $vehicle;
    }

    private function purge(FuelLevelService $fuelLevels, ?Vehicle $vehicle): int
    {
        $deleted = $fuelLevels->purgeBySource(VehicleFuelReading::SOURCE_SIMULATED, $vehicle);

        $this->info(sprintf(
            'Purged %d simulated reading(s)%s. Affected vehicles now reflect their remaining real history.',
            $deleted,
            $vehicle !== null ? " for vehicle {$vehicle->getKey()}" : '',
        ));

        return self::SUCCESS;
    }

    private function generate(FuelLevelService $fuelLevels, Vehicle $vehicle, string $scenario): int
    {
        $count = max(2, (int) $this->option('readings'));
        $minutes = max(1, (int) $this->option('minutes'));
        $start = min(100.0, max(0.0, (float) $this->option('start')));

        $levels = $this->levelsFor($scenario, $start, $count);

        // Spread the samples across the window, ending now.
        $step = $minutes / max(1, count($levels) - 1);
        $begunAt = now()->subMinutes($minutes);
        $ids = [];

        foreach ($levels as $index => $level) {
            $recordedAt = (clone $begunAt)->addSeconds((int) round($index * $step * 60));

            $reading = $fuelLevels->record($vehicle, [
                'fuel_pct' => $level,
                'recorded_at' => $recordedAt,
                'source' => VehicleFuelReading::SOURCE_SIMULATED,
            ]);

            // Counting calls rather than rows would overstate the result: a
            // window too short for the sample count collapses two readings onto
            // the same second, and the service then returns the stored one.
            $ids[$reading->getKey()] = true;
        }

        $written = count($ids);

        $vehicle->refresh();

        $this->info(sprintf(
            'Wrote %d simulated reading(s) for %s over %d minute(s) [%s].',
            $written,
            $vehicle->plate_number,
            $minutes,
            $scenario,
        ));

        $this->table(
            ['level start', 'level end', 'largest drop', 'vehicle now', 'status'],
            [[
                sprintf('%.1f%%', $levels[0]),
                sprintf('%.1f%%', end($levels)),
                sprintf('%.1f pts', $this->largestDrop($levels)),
                sprintf('%.1f%%', (float) $vehicle->current_fuel_pct),
                $fuelLevels->statusFor($vehicle->current_fuel_pct) ?? '—',
            ]],
        );

        if ($scenario === 'vehicle_offline') {
            $this->line('The vehicle then stops reporting — the gap after the last reading is the signal.');
        }

        return self::SUCCESS;
    }

    /**
     * The level series for each scenario.
     *
     * Shapes rather than randomness: a scenario is useful because it is
     * recognisable, and a test asserting "a sudden loss produces a large
     * negative delta" needs the shape to be deterministic.
     *
     * @return list<float>
     */
    private function levelsFor(string $scenario, float $start, int $count): array
    {
        $levels = [];

        switch ($scenario) {
            case 'refill':
                // Burns down, then jumps back to near-full at the pump.
                $half = (int) floor($count / 2);
                for ($i = 0; $i < $half; $i++) {
                    $levels[] = max(5.0, $start - ($i * 2.5));
                }
                $topUp = min(100.0, $start + 15.0);
                for ($i = $half; $i < $count; $i++) {
                    $levels[] = max(5.0, $topUp - (($i - $half) * 1.5));
                }
                break;

            case 'sudden_loss':
                // Steady, then a cliff — the siphon signature. The drop lands
                // between two adjacent samples, so its whole magnitude shows up
                // in one delta.
                $cliff = (int) floor($count / 2);
                for ($i = 0; $i < $count; $i++) {
                    $levels[] = $i < $cliff
                        ? max(0.0, $start - ($i * 1.0))
                        : max(0.0, $start - ($cliff * 1.0) - 40.0 - (($i - $cliff) * 1.0));
                }
                break;

            case 'gradual_loss':
                // A slow leak: consumption plus a constant bleed, so no single
                // delta looks alarming but the trend does.
                for ($i = 0; $i < $count; $i++) {
                    $levels[] = max(0.0, $start - ($i * 4.5));
                }
                break;

            case 'sensor_anomaly':
                // A gauge that oscillates implausibly around a steady tank.
                for ($i = 0; $i < $count; $i++) {
                    $levels[] = min(100.0, max(0.0, $start + ($i % 2 === 0 ? -22.0 : 18.0)));
                }
                break;

            case 'vehicle_offline':
                // Normal readings that simply stop. Only a couple of samples,
                // because the silence afterwards is the whole point.
                $stops = max(2, (int) ceil($count / 4));
                for ($i = 0; $i < $stops; $i++) {
                    $levels[] = max(0.0, $start - ($i * 1.5));
                }
                break;

            case 'normal_consumption':
            default:
                for ($i = 0; $i < $count; $i++) {
                    $levels[] = max(0.0, $start - ($i * 1.5));
                }
                break;
        }

        return $levels;
    }

    /** @param list<float> $levels */
    private function largestDrop(array $levels): float
    {
        $largest = 0.0;

        for ($i = 1; $i < count($levels); $i++) {
            $largest = max($largest, $levels[$i - 1] - $levels[$i]);
        }

        return $largest;
    }
}
