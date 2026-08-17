<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Services;

use App\Domain\Ai\Models\FraudAlert;
use App\Domain\Expense\Models\FuelPurchase;
use App\Domain\Fleet\Contracts\FuelAnomalyScreener;
use App\Domain\Fleet\Models\VehicleFuelReading;
use App\Domain\Vehicle\Models\Vehicle;

/**
 * Detects fuel movement that the vehicle's own history does not explain.
 *
 * This is the question the telemetry work exists to answer. A fill-up ledger
 * can only say what was bought; a level series can show fuel leaving the tank
 * when nobody bought anything — the 80% to 40% in a minute case.
 *
 * Deliberately not called theft, and the alert types say so. A steep drop has
 * several plausible causes — siphoning, a leak, a sensor fault, maintenance
 * draining a tank, a miscalibrated float — and this service cannot tell them
 * apart. It records what it measured, scores how unusual that is, and leaves
 * the conclusion to the person resolving the alert. `evidence` carries the
 * figures so that person can disagree with it.
 *
 * Scoring mirrors FraudDetectionService: independent signals combine as a
 * noisy-OR, so several weak ones still add up and no single rule can saturate.
 * Sharing the shape matters — the two detectors write to the same table, and an
 * operator comparing a 0.8 from one against a 0.8 from the other should be
 * comparing like with like.
 */
final readonly class FuelAnomalyDetector implements FuelAnomalyScreener
{
    public function screen(VehicleFuelReading $reading): ?FraudAlert
    {
        $vehicle = $reading->vehicle;

        // The first reading for a vehicle has nothing to be abnormal against.
        if ($vehicle === null || $reading->delta_pct === null) {
            return null;
        }

        $signals = $this->evaluate($reading, $vehicle);

        if ($signals === []) {
            return null;
        }

        $score = 1.0;
        foreach ($signals as $signal) {
            $score *= (1 - $signal['weight']);
        }
        $score = round(1 - $score, 3);

        if ($score < (float) config('fip.fuel_anomaly.score_threshold')) {
            return null;
        }

        $primary = collect($signals)->sortByDesc('weight')->first();

        return FraudAlert::create([
            'company_id' => $vehicle->company_id,
            'fleet_id' => $vehicle->fleet_id,
            'vehicle_id' => $vehicle->getKey(),
            'driver_id' => $vehicle->currentAssignment?->driver_id,
            'vehicle_fuel_reading_id' => $reading->getKey(),
            'alert_type' => $primary['type'],
            'severity' => FraudAlert::severityForScore($score),
            'score' => $score,
            'evidence' => [
                'signals' => $signals,
                'reading_id' => $reading->getKey(),
                'fuel_pct' => $reading->fuel_pct,
                'delta_pct' => $reading->delta_pct,
                'source' => $reading->source,
                'recorded_at' => $reading->recorded_at->toIso8601String(),
            ],
            'status' => FraudAlert::STATUS_OPEN,
            'detected_at' => now(),
        ]);
    }

    /**
     * @return array<int, array{type: string, weight: float, detail: array}>
     */
    private function evaluate(VehicleFuelReading $reading, Vehicle $vehicle): array
    {
        $signals = [];
        $previous = $this->previousReading($reading);

        if ($previous === null) {
            return [];
        }

        $minutes = max(0.0, (float) $previous->recorded_at->diffInSeconds($reading->recorded_at) / 60);
        $delta = (float) $reading->delta_pct;

        // --- Rule 1: a steep fall with nothing bought to explain it ---------
        $dropThreshold = (float) config('fip.fuel_anomaly.drop_pct');
        $window = (float) config('fip.fuel_anomaly.drop_window_minutes');

        if ($delta <= -$dropThreshold && $minutes <= $window && ! $this->explainedByPurchase($reading, $vehicle)) {
            $magnitude = abs($delta) / max($dropThreshold, 1);

            $signals[] = [
                'type' => 'abnormal_fuel_drop',
                // Starts at the alert threshold so that crossing the
                // configured drop actually raises something: an earlier base of
                // 0.55 meant `drop_pct = 15` silently required about 22 points
                // before anything fired, and a setting that does not mean what
                // it says is worse than no setting. Scales with magnitude, and
                // is capped short of 1 so no single rule asserts certainty.
                'weight' => min(0.95, $this->threshold() + ($magnitude - 1) * 0.15),
                'detail' => [
                    'from_pct' => $previous->fuel_pct,
                    'to_pct' => $reading->fuel_pct,
                    'dropped_pct' => round(abs($delta), 2),
                    'over_minutes' => round($minutes, 1),
                    'litres_lost' => $this->litresFor($vehicle, abs($delta)),
                ],
            ];
        }

        // --- Rule 2: fuel that appeared without a purchase ------------------
        $gainThreshold = (float) config('fip.fuel_anomaly.gain_pct');

        if ($delta >= $gainThreshold && ! $this->explainedByPurchase($reading, $vehicle)) {
            $signals[] = [
                'type' => 'unexplained_fuel_gain',
                // At the threshold exactly, so it raises the lowest severity
                // band on its own. A weight below the threshold would have made
                // this rule unreachable except in combination — present in the
                // code and never able to fire, which is the worst of both.
                // Kept at the floor because the likeliest cause is a fill-up
                // nobody logged: a paperwork problem, not a loss.
                'weight' => $this->threshold(),
                'detail' => [
                    'from_pct' => $previous->fuel_pct,
                    'to_pct' => $reading->fuel_pct,
                    'gained_pct' => round($delta, 2),
                    'over_minutes' => round($minutes, 1),
                ],
            ];
        }

        // --- Rule 3: a fall immediately undone ------------------------------
        // No tank refills itself. A large drop followed by a comparable rise
        // with no purchase between them is the gauge misreporting, not fuel
        // moving, and saying so keeps it out of the loss figures.
        $reversal = (float) config('fip.fuel_anomaly.sensor_reversal_pct');

        if ($previous->delta_pct !== null
            && $delta >= $reversal
            && (float) $previous->delta_pct <= -$reversal
            && ! $this->explainedByPurchase($reading, $vehicle)) {
            $signals[] = [
                'type' => 'sensor_anomaly',
                'weight' => 0.7,
                'detail' => [
                    'fell_pct' => round(abs((float) $previous->delta_pct), 2),
                    'rose_pct' => round($delta, 2),
                    'note' => 'A fall reversed without a recorded fill-up reads as a gauge fault.',
                ],
            ];
        }

        return $signals;
    }

    /** The score at or above which an alert is raised. */
    private function threshold(): float
    {
        return (float) config('fip.fuel_anomaly.score_threshold');
    }

    /** The reading immediately before this one, by its own clock. */
    private function previousReading(VehicleFuelReading $reading): ?VehicleFuelReading
    {
        return VehicleFuelReading::query()
            ->where('vehicle_id', $reading->vehicle_id)
            ->where('recorded_at', '<', $reading->recorded_at)
            ->orderByDesc('recorded_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Whether a recorded fill-up sits close enough in time to account for the
     * change. Checked for falls as well as rises: a tank drained during a
     * service visit that was logged as a purchase is not a loss.
     */
    private function explainedByPurchase(VehicleFuelReading $reading, Vehicle $vehicle): bool
    {
        if ($reading->fuel_purchase_id !== null) {
            return true;
        }

        $window = (int) config('fip.fuel_anomaly.purchase_match_minutes');

        return FuelPurchase::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->whereBetween('purchased_at', [
                $reading->recorded_at->copy()->subMinutes($window),
                $reading->recorded_at->copy()->addMinutes($window),
            ])
            ->exists();
    }

    /** Percentage points as litres, when the tank capacity is known. */
    private function litresFor(Vehicle $vehicle, float $points): ?float
    {
        return $vehicle->tank_capacity !== null
            ? round($vehicle->tank_capacity * ($points / 100), 2)
            : null;
    }
}
