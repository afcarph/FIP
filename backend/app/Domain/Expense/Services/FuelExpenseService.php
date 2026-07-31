<?php

declare(strict_types=1);

namespace App\Domain\Expense\Services;

use App\Domain\Ai\Services\FraudDetectionService;
use App\Domain\Expense\Models\FuelPurchase;
use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\OdometerReading;
use App\Domain\Vehicle\Models\Vehicle;
use App\Support\Exceptions\DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Fuel expense recording and analytics.
 *
 * Derived metrics are computed once, at write time, from the previous
 * full-tank fill-up: distance travelled, km/L and cost/km. Doing it here
 * rather than in reports means every consumer — dashboard, exports, AI
 * features — sees identical numbers.
 */
final readonly class FuelExpenseService
{
    public function __construct(private FraudDetectionService $fraud) {}

    public function record(User $user, Vehicle $vehicle, array $data): FuelPurchase
    {
        $purchasedAt = Carbon::parse($data['purchased_at'] ?? now());
        $litres = round((float) $data['litres'], 3);
        $pricePerLitre = round((float) $data['price_per_litre'], 4);
        $totalCost = isset($data['total_cost'])
            ? round((float) $data['total_cost'], 2)
            : round($litres * $pricePerLitre, 2);

        $this->assertConsistent($litres, $pricePerLitre, $totalCost);
        $this->assertOdometerMonotonic($vehicle, $data['odometer'] ?? null, $purchasedAt);

        $purchase = DB::transaction(function () use ($user, $vehicle, $data, $purchasedAt, $litres, $pricePerLitre, $totalCost): FuelPurchase {
            $previous = $this->previousFullTank($vehicle, $purchasedAt);

            $odometer = isset($data['odometer']) ? (float) $data['odometer'] : null;
            $distance = ($odometer !== null && $previous?->odometer !== null)
                ? round($odometer - (float) $previous->odometer, 2)
                : null;

            // km/L is only meaningful tank-to-tank between two full fills.
            $isFullTank = (bool) ($data['is_full_tank'] ?? true);
            $kmPerLitre = ($distance !== null && $distance > 0 && $isFullTank && $litres > 0)
                ? round($distance / $litres, 2)
                : null;

            $costPerKm = ($distance !== null && $distance > 0)
                ? round($totalCost / $distance, 4)
                : null;

            $purchase = FuelPurchase::create([
                'vehicle_id' => $vehicle->getKey(),
                'user_id' => $user->getKey(),
                'driver_id' => $data['driver_id'] ?? $vehicle->currentAssignment?->driver_id,
                'station_id' => $data['station_id'] ?? null,
                'fuel_type_id' => $data['fuel_type_id'] ?? $vehicle->fuel_type_id,
                'litres' => $litres,
                'price_per_litre' => $pricePerLitre,
                'total_cost' => $totalCost,
                'odometer' => $odometer,
                'distance_since_last' => $distance,
                'km_per_litre' => $kmPerLitre,
                'cost_per_km' => $costPerKm,
                'is_full_tank' => $isFullTank,
                'payment_method_id' => $data['payment_method_id'] ?? null,
                'receipt_path' => $data['receipt_path'] ?? null,
                'notes' => $data['notes'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'purchased_at' => $purchasedAt,
            ]);

            if ($odometer !== null) {
                OdometerReading::create([
                    'vehicle_id' => $vehicle->getKey(),
                    'reading' => $odometer,
                    'source' => 'fuel_log',
                    'recorded_at' => $purchasedAt,
                    'recorded_by' => $user->getKey(),
                ]);

                if ($odometer > $vehicle->current_odometer) {
                    $vehicle->forceFill(['current_odometer' => $odometer])->saveQuietly();
                }
            }

            return $purchase;
        });

        $vehicle->recalculateEfficiency();
        $this->fraud->screen($purchase);

        return $purchase->refresh();
    }

    public function update(FuelPurchase $purchase, User $user, array $data): FuelPurchase
    {
        if (! $purchase->isEditableBy($user)) {
            throw new DomainException(
                'This fill-up is outside your edit window; ask a fleet manager to amend it.',
                'edit_window_expired',
                403,
            );
        }

        $purchase->update($data);
        $purchase->vehicle?->recalculateEfficiency();

        return $purchase->refresh();
    }

    /**
     * Headline expense figures for a period.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<FuelPurchase>  $scope
     */
    public function summary($scope, Carbon $from, Carbon $to): array
    {
        $row = (clone $scope)
            ->betweenPeriod($from, $to)
            ->selectRaw('
                COUNT(*) AS fill_ups,
                COALESCE(SUM(litres), 0) AS total_litres,
                COALESCE(SUM(total_cost), 0) AS total_cost,
                COALESCE(SUM(distance_since_last), 0) AS total_distance,
                AVG(price_per_litre) AS avg_price_per_litre,
                AVG(km_per_litre) AS avg_km_per_litre,
                AVG(cost_per_km) AS avg_cost_per_km
            ')
            ->first();

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'fill_ups' => (int) $row->fill_ups,
            'total_litres' => round((float) $row->total_litres, 2),
            'total_cost' => round((float) $row->total_cost, 2),
            'total_distance_km' => round((float) $row->total_distance, 2),
            'avg_price_per_litre' => $row->avg_price_per_litre !== null ? round((float) $row->avg_price_per_litre, 4) : null,
            'avg_km_per_litre' => $row->avg_km_per_litre !== null ? round((float) $row->avg_km_per_litre, 2) : null,
            'avg_cost_per_km' => $row->avg_cost_per_km !== null ? round((float) $row->avg_cost_per_km, 4) : null,
        ];
    }

    /** Month-by-month spend series for the expense chart. */
    public function monthlySeries($scope, int $months = 12): array
    {
        return (clone $scope)
            ->where('purchased_at', '>=', now()->subMonths($months)->startOfMonth())
            ->selectRaw("
                DATE_FORMAT(purchased_at, '%Y-%m') AS period,
                SUM(total_cost) AS total_cost,
                SUM(litres) AS total_litres,
                AVG(price_per_litre) AS avg_price,
                COUNT(*) AS fill_ups
            ")
            ->groupBy('period')
            ->orderBy('period')
            ->get()
            ->map(static fn ($row) => [
                'period' => $row->period,
                'total_cost' => round((float) $row->total_cost, 2),
                'total_litres' => round((float) $row->total_litres, 2),
                'avg_price' => round((float) $row->avg_price, 4),
                'fill_ups' => (int) $row->fill_ups,
            ])->all();
    }

    /**
     * What the user would have paid at the cheapest station they passed,
     * versus what they actually paid. Drives the "savings" headline.
     */
    public function savingsAnalysis($scope, Carbon $from, Carbon $to): array
    {
        $purchases = (clone $scope)
            ->betweenPeriod($from, $to)
            ->whereNotNull('station_id')
            ->with('station.city')
            ->get();

        $actual = 0.0;
        $best = 0.0;

        foreach ($purchases as $purchase) {
            $cityMedian = DB::table('station_prices as sp')
                ->join('gas_stations as gs', 'gs.id', '=', 'sp.station_id')
                ->where('gs.city_id', $purchase->station?->city_id)
                ->where('sp.fuel_type_id', $purchase->fuel_type_id)
                ->min('sp.price');

            $actual += (float) $purchase->total_cost;
            $best += $cityMedian !== null
                ? (float) $purchase->litres * (float) $cityMedian
                : (float) $purchase->total_cost;
        }

        return [
            'actual_spend' => round($actual, 2),
            'best_case_spend' => round($best, 2),
            'potential_savings' => round(max($actual - $best, 0), 2),
            'savings_pct' => $actual > 0 ? round((max($actual - $best, 0) / $actual) * 100, 2) : 0.0,
            'sample_size' => $purchases->count(),
        ];
    }

    // ------------------------------------------------------------ internals

    private function previousFullTank(Vehicle $vehicle, Carbon $before): ?FuelPurchase
    {
        return $vehicle->fuelPurchases()
            ->where('purchased_at', '<', $before)
            ->where('is_full_tank', true)
            ->latest('purchased_at')
            ->first();
    }

    private function assertConsistent(float $litres, float $pricePerLitre, float $totalCost): void
    {
        if ($litres <= 0) {
            throw new DomainException('Litres must be greater than zero.', 'invalid_litres');
        }

        $expected = $litres * $pricePerLitre;

        // Allow ₱1 of rounding slack — pumps round, receipts round differently.
        if (abs($expected - $totalCost) > max(1.0, $expected * 0.02)) {
            throw new DomainException(
                sprintf('Total ₱%.2f does not match %.2f L × ₱%.2f (₱%.2f).', $totalCost, $litres, $pricePerLitre, $expected),
                'inconsistent_total',
                422,
                ['expected_total' => round($expected, 2), 'submitted_total' => $totalCost],
            );
        }
    }

    private function assertOdometerMonotonic(Vehicle $vehicle, mixed $odometer, Carbon $purchasedAt): void
    {
        if ($odometer === null) {
            return;
        }

        $previous = $vehicle->fuelPurchases()
            ->where('purchased_at', '<', $purchasedAt)
            ->whereNotNull('odometer')
            ->latest('purchased_at')
            ->value('odometer');

        if ($previous !== null && (float) $odometer < (float) $previous) {
            throw new DomainException(
                sprintf('Odometer %.2f km is lower than the previous reading of %.2f km.', (float) $odometer, (float) $previous),
                'odometer_rollback',
                422,
                ['previous_odometer' => (float) $previous, 'submitted_odometer' => (float) $odometer],
            );
        }
    }
}
