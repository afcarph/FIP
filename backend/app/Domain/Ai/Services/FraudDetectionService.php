<?php

declare(strict_types=1);

namespace App\Domain\Ai\Services;

use App\Domain\Ai\Models\FraudAlert;
use App\Domain\Expense\Contracts\FraudScreener;
use App\Domain\Expense\Models\FuelPurchase;
use App\Services\External\AiServiceClient;
use App\Support\Concerns\GeoDistance;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Two-tier fuel fraud detection.
 *
 * Tier 1 — deterministic rules that run inline on every fill-up. They are
 * cheap, explainable and catch the common abuses: overfilling beyond tank
 * capacity, impossible efficiency swings, odometer rollbacks, quick repeat
 * fills and price mismatches against the station's published board.
 *
 * Tier 2 — an unsupervised model (isolation forest) in the AI service that
 * scores a driver's whole recent history and catches slow-burn patterns the
 * rules miss. It runs on a schedule, not inline.
 *
 * Each rule contributes a weighted score; the combined score decides both
 * whether an alert is raised and how severe it is.
 */
final readonly class FraudDetectionService implements FraudScreener
{
    use GeoDistance;

    public function __construct(private AiServiceClient $ai) {}

    /**
     * Score one purchase and raise an alert when it crosses the threshold.
     * Called synchronously from FuelExpenseService.
     */
    public function screen(FuelPurchase $purchase): ?FraudAlert
    {
        $signals = $this->evaluateRules($purchase);

        if ($signals === []) {
            $purchase->forceFill(['anomaly_score' => 0.0])->saveQuietly();

            return null;
        }

        // Combine independent signals as a noisy-OR: several weak signals
        // together should still raise a flag, but no single rule can exceed 1.
        $score = 1.0;
        foreach ($signals as $signal) {
            $score *= (1 - $signal['weight']);
        }
        $score = round(1 - $score, 3);

        $purchase->forceFill(['anomaly_score' => $score])->saveQuietly();

        if ($score < (float) config('fip.fraud.score_threshold')) {
            return null;
        }

        $primary = collect($signals)->sortByDesc('weight')->first();

        return FraudAlert::create([
            'company_id' => $purchase->vehicle?->company_id,
            'fleet_id' => $purchase->vehicle?->fleet_id,
            'vehicle_id' => $purchase->vehicle_id,
            'driver_id' => $purchase->driver_id,
            'fuel_purchase_id' => $purchase->getKey(),
            'alert_type' => $primary['type'],
            'severity' => FraudAlert::severityForScore($score),
            'score' => $score,
            'evidence' => ['signals' => $signals, 'purchase_id' => $purchase->getKey()],
            'status' => FraudAlert::STATUS_OPEN,
            'detected_at' => now(),
        ]);
    }

    /**
     * @return array<int, array{type: string, weight: float, detail: array}>
     */
    private function evaluateRules(FuelPurchase $purchase): array
    {
        $signals = [];
        $vehicle = $purchase->vehicle;

        if ($vehicle === null) {
            return [];
        }

        // --- Rule 1: more litres than the tank physically holds -------------
        $tolerance = (float) config('fip.fraud.overfill_tolerance_pct');

        if ($vehicle->tank_capacity && $purchase->litres > $vehicle->tank_capacity * (1 + $tolerance)) {
            $excess = round($purchase->litres - $vehicle->tank_capacity, 2);

            $signals[] = [
                'type' => 'overfill',
                'weight' => min(0.9, 0.5 + ($excess / max($vehicle->tank_capacity, 1))),
                'detail' => [
                    'litres' => $purchase->litres,
                    'tank_capacity' => $vehicle->tank_capacity,
                    'excess_litres' => $excess,
                ],
            ];
        }

        // --- Rule 2: efficiency far off this vehicle's own baseline ---------
        $baseline = $vehicle->baseline_km_per_litre ?? $vehicle->avg_km_per_litre;

        if ($baseline && $purchase->km_per_litre) {
            $deviation = abs($purchase->km_per_litre - $baseline) / $baseline;

            if ($deviation > 0.35) {
                $signals[] = [
                    'type' => $purchase->km_per_litre > $baseline ? 'ghost_refuel' : 'excess_consumption',
                    'weight' => min(0.85, $deviation),
                    'detail' => [
                        'km_per_litre' => $purchase->km_per_litre,
                        'baseline_km_per_litre' => $baseline,
                        'deviation_pct' => round($deviation * 100, 1),
                    ],
                ];
            }
        }

        // --- Rule 3: two fills implausibly close together -------------------
        $previous = FuelPurchase::query()
            ->where('vehicle_id', $vehicle->getKey())
            ->where('id', '!=', $purchase->getKey())
            ->where('purchased_at', '<', $purchase->purchased_at)
            ->latest('purchased_at')
            ->first();

        if ($previous !== null) {
            $minutes = $previous->purchased_at->diffInMinutes($purchase->purchased_at);

            if ($minutes < (int) config('fip.fraud.min_minutes_between_fills')) {
                $signals[] = [
                    'type' => 'rapid_refuel',
                    'weight' => 0.7,
                    'detail' => ['minutes_since_previous' => $minutes, 'previous_purchase_id' => $previous->getKey()],
                ];
            }

            // Rule 4: odometer went backwards between consecutive fills.
            if ($previous->odometer !== null && $purchase->odometer !== null && $purchase->odometer < $previous->odometer) {
                $signals[] = [
                    'type' => 'odometer_rollback',
                    'weight' => 0.88,
                    'detail' => ['previous' => $previous->odometer, 'current' => $purchase->odometer],
                ];
            }
        }

        // --- Rule 5: price paid differs from the station's published board ---
        $published = $purchase->station?->priceFor((int) $purchase->fuel_type_id);

        if ($published !== null) {
            $delta = abs((float) $published->price - $purchase->price_per_litre);

            if ($delta > 2.0) {
                $signals[] = [
                    'type' => 'price_mismatch',
                    'weight' => min(0.8, 0.3 + $delta / 20),
                    'detail' => [
                        'claimed_price' => $purchase->price_per_litre,
                        'published_price' => (float) $published->price,
                        'difference' => round($delta, 2),
                    ],
                ];
            }
        }

        // --- Rule 6: transaction geotag far from the claimed station --------
        if ($purchase->latitude !== null && $purchase->longitude !== null && $purchase->station !== null) {
            $distanceKm = $this->distanceInKilometres(
                $purchase->latitude,
                $purchase->longitude,
                $purchase->station->latitude,
                $purchase->station->longitude,
            );

            if ($distanceKm > 2.0) {
                $signals[] = [
                    'type' => 'location_mismatch',
                    'weight' => min(0.75, 0.3 + $distanceKm / 50),
                    'detail' => ['distance_km' => round($distanceKm, 2), 'station_id' => $purchase->station_id],
                ];
            }
        }

        return $signals;
    }

    /**
     * Tier 2 — hand a fleet's recent transactions to the unsupervised model
     * and raise alerts for anything the rules did not already flag.
     *
     * @param Collection<int, FuelPurchase> $purchases
     * @return array<int, FraudAlert>
     */
    public function screenBatch($purchases): array
    {
        if ($purchases->isEmpty()) {
            return [];
        }

        try {
            $response = $this->ai->detectFraud([
                'transactions' => $purchases->map(static fn (FuelPurchase $p) => [
                    'id' => $p->getKey(),
                    'vehicle_id' => $p->vehicle_id,
                    'driver_id' => $p->driver_id,
                    'litres' => $p->litres,
                    'price_per_litre' => $p->price_per_litre,
                    'total_cost' => $p->total_cost,
                    'odometer' => $p->odometer,
                    'distance_since_last' => $p->distance_since_last,
                    'km_per_litre' => $p->km_per_litre,
                    'tank_capacity' => $p->vehicle?->tank_capacity,
                    'baseline_km_per_litre' => $p->vehicle?->baseline_km_per_litre,
                    'purchased_at' => $p->purchased_at->toIso8601String(),
                    'latitude' => $p->latitude,
                    'longitude' => $p->longitude,
                ])->values()->all(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Batch fraud screening skipped', ['error' => $e->getMessage()]);

            return [];
        }

        $alerts = [];

        foreach ($response['results'] ?? [] as $result) {
            $score = (float) ($result['anomaly_score'] ?? 0);

            if ($score < (float) config('fip.fraud.score_threshold')) {
                continue;
            }

            $purchase = $purchases->firstWhere('id', $result['id'] ?? null);

            if ($purchase === null || $this->alreadyFlagged($purchase)) {
                continue;
            }

            $purchase->forceFill(['anomaly_score' => $score])->saveQuietly();

            $alerts[] = FraudAlert::create([
                'company_id' => $purchase->vehicle?->company_id,
                'fleet_id' => $purchase->vehicle?->fleet_id,
                'vehicle_id' => $purchase->vehicle_id,
                'driver_id' => $purchase->driver_id,
                'fuel_purchase_id' => $purchase->getKey(),
                'alert_type' => $result['alert_type'] ?? 'anomalous_pattern',
                'severity' => FraudAlert::severityForScore($score),
                'score' => $score,
                'evidence' => ['model' => 'isolation_forest', 'features' => $result['contributing_features'] ?? []],
                'status' => FraudAlert::STATUS_OPEN,
                'detected_at' => now(),
            ]);
        }

        return $alerts;
    }

    private function alreadyFlagged(FuelPurchase $purchase): bool
    {
        return FraudAlert::where('fuel_purchase_id', $purchase->getKey())->exists();
    }
}
