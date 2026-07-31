<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\Ai\Models\FraudAlert;
use App\Domain\Ai\Services\PriceForecastService;
use App\Domain\Expense\Models\FuelPurchase;
use App\Domain\Expense\Services\FuelExpenseService;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Maintenance\Models\MaintenanceSchedule;
use App\Domain\Pricing\Models\PriceReport;
use App\Domain\Pricing\Repositories\PriceRepository;
use App\Domain\Station\Models\GasStation;
use App\Domain\User\Models\User;
use App\Domain\User\Repositories\UserRepository;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Repositories\VehicleRepository;
use Illuminate\Support\Facades\Cache;

/**
 * Assembles the three dashboards — personal, fleet and executive — each from
 * the same underlying repositories but scoped and aggregated differently.
 *
 * Every widget is individually cacheable; the executive view is the expensive
 * one and is cached hardest because its inputs move slowly.
 */
final readonly class DashboardService
{
    public function __construct(
        private FuelExpenseService $expenses,
        private PriceForecastService $forecasts,
        private PriceRepository $prices,
        private VehicleRepository $vehicles,
        private UserRepository $users,
    ) {}

    /** Personal dashboard for a motorist. */
    public function forUser(User $user): array
    {
        $from = now()->startOfMonth();
        $to = now();

        $summary = $this->expenses->summary($user->fuelPurchases(), $from, $to);
        $savings = $this->expenses->savingsAnalysis($user->fuelPurchases(), now()->subDays(90), $to);

        return [
            'summary' => $summary,
            'savings' => $savings,
            'monthly_series' => $this->expenses->monthlySeries($user->fuelPurchases(), 12),
            'vehicles' => $user->vehicles()->active()->with('fuelType')->get()->map(fn (Vehicle $v) => [
                'id' => $v->getKey(),
                'name' => $v->display_name,
                'plate_number' => $v->plate_number,
                'fuel_type' => $v->fuelType?->name,
                'odometer' => $v->current_odometer,
                'avg_km_per_litre' => $v->avg_km_per_litre,
                'efficiency_deviation_pct' => $v->efficiencyDeviationPct(),
                'estimated_range_km' => $v->estimatedRangeKm(),
            ])->all(),
            'forecasts' => $this->forecastWidget(),
            'maintenance_due' => MaintenanceSchedule::query()
                ->whereIn('vehicle_id', $user->vehicles()->select('id'))
                ->due()
                ->with('type', 'vehicle')
                ->limit(5)
                ->get()
                ->map(static fn (MaintenanceSchedule $s) => [
                    'vehicle' => $s->vehicle?->display_name,
                    'service' => $s->type?->name,
                    'status' => $s->status,
                    'due_at' => $s->due_at?->toDateString(),
                ])->all(),
            'unread_notifications' => $user->appNotifications()->unread()->count(),
        ];
    }

    /** Fleet manager dashboard, scoped to one company (optionally one fleet). */
    public function forFleet(int $companyId, ?int $fleetId = null): array
    {
        $from = now()->startOfMonth();
        $to = now();

        $purchaseScope = FuelPurchase::query()
            ->whereIn('vehicle_id', Vehicle::query()
                ->where('company_id', $companyId)
                ->when($fleetId, fn ($q) => $q->where('fleet_id', $fleetId))
                ->select('id'));

        $vehicleStats = $this->vehicles->fleetStatistics($companyId, $fleetId);

        return [
            'vehicles' => $vehicleStats,
            'drivers' => [
                'total' => Driver::where('company_id', $companyId)->when($fleetId, fn ($q) => $q->where('fleet_id', $fleetId))->count(),
                'active' => Driver::where('company_id', $companyId)->when($fleetId, fn ($q) => $q->where('fleet_id', $fleetId))->active()->count(),
                'licence_expiring_30d' => Driver::where('company_id', $companyId)->licenceExpiringWithin(30)->count(),
            ],
            'summary' => $this->expenses->summary(clone $purchaseScope, $from, $to),
            'monthly_series' => $this->expenses->monthlySeries(clone $purchaseScope, 12),
            'savings' => $this->expenses->savingsAnalysis(clone $purchaseScope, now()->subDays(90), $to),
            'top_consumers' => $this->topConsumers($companyId, $fleetId),
            'fraud_alerts' => [
                'open' => FraudAlert::where('company_id', $companyId)->unresolved()->count(),
                'critical' => FraudAlert::where('company_id', $companyId)->unresolved()->where('severity', 'critical')->count(),
                'recent' => FraudAlert::where('company_id', $companyId)
                    ->unresolved()
                    ->with('vehicle', 'driver')
                    ->latest('detected_at')
                    ->limit(5)
                    ->get()
                    ->map(static fn (FraudAlert $a) => [
                        'id' => $a->getKey(),
                        'type' => $a->alert_type,
                        'severity' => $a->severity,
                        'score' => $a->score,
                        'vehicle' => $a->vehicle?->plate_number,
                        'driver' => $a->driver?->full_name,
                        'detected_at' => $a->detected_at->toIso8601String(),
                    ])->all(),
            ],
            'maintenance' => [
                'overdue' => $this->maintenanceCount($companyId, $fleetId, MaintenanceSchedule::STATUS_OVERDUE),
                'due_soon' => $this->maintenanceCount($companyId, $fleetId, MaintenanceSchedule::STATUS_DUE_SOON),
            ],
            'utilisation' => $this->utilisation($companyId, $fleetId),
        ];
    }

    /** Platform-wide executive dashboard. */
    public function executive(): array
    {
        return Cache::remember('dashboard:executive', now()->addMinutes(15), function (): array {
            $defaultFuelTypeId = 2;   // RON 95 is the headline benchmark

            return [
                'platform' => [
                    'users' => $this->users->query()->count(),
                    'active_users_30d' => $this->users->query()->where('last_login_at', '>=', now()->subDays(30))->count(),
                    'companies' => \App\Domain\User\Models\Company::count(),
                    'vehicles' => Vehicle::count(),
                    'stations' => GasStation::active()->count(),
                    'fill_ups_30d' => FuelPurchase::where('purchased_at', '>=', now()->subDays(30))->count(),
                ],
                'user_growth' => $this->users->growthByMonth(12),
                'price_comparison' => $this->prices->comparisonMatrix(),
                'regional_movement' => $this->prices->regionalMovement($defaultFuelTypeId),
                'national_trend' => $this->prices->nationalTrend($defaultFuelTypeId, 180),
                'forecasts' => $this->forecastWidget(),
                'forecast_accuracy' => $this->forecasts->accuracySummary(),
                'crowd' => [
                    'pending_reports' => PriceReport::pending()->count(),
                    'approved_30d' => PriceReport::published()->where('created_at', '>=', now()->subDays(30))->count(),
                    'contributors_30d' => PriceReport::where('created_at', '>=', now()->subDays(30))->distinct('user_id')->count('user_id'),
                ],
                'aggregate_savings' => $this->platformSavings(),
            ];
        });
    }

    // ------------------------------------------------------------ internals

    private function forecastWidget(): array
    {
        return collect($this->forecasts->latest())->map(static fn ($f) => [
            'fuel_type_id' => $f->fuel_type_id,
            'fuel_type' => $f->fuelType?->name,
            'direction' => $f->direction,
            'change_amount' => $f->change_amount,
            'confidence' => $f->confidence,
            'label' => $f->label(),
            'narrative' => $f->narrative,
            'effective_week' => $f->forecast_for?->toDateString(),
            'drivers' => $f->topDrivers(),
        ])->all();
    }

    private function topConsumers(int $companyId, ?int $fleetId, int $limit = 10): array
    {
        return FuelPurchase::query()
            ->join('vehicles', 'vehicles.id', '=', 'fuel_purchases.vehicle_id')
            ->where('vehicles.company_id', $companyId)
            ->when($fleetId, fn ($q) => $q->where('vehicles.fleet_id', $fleetId))
            ->where('fuel_purchases.purchased_at', '>=', now()->startOfMonth())
            ->selectRaw('
                vehicles.id, vehicles.plate_number, vehicles.nickname,
                SUM(fuel_purchases.total_cost) AS total_cost,
                SUM(fuel_purchases.litres) AS total_litres,
                AVG(fuel_purchases.km_per_litre) AS avg_kpl
            ')
            ->groupBy('vehicles.id', 'vehicles.plate_number', 'vehicles.nickname')
            ->orderByDesc('total_cost')
            ->limit($limit)
            ->get()
            ->map(static fn ($row) => [
                'vehicle_id' => (int) $row->id,
                'plate_number' => $row->plate_number,
                'nickname' => $row->nickname,
                'total_cost' => round((float) $row->total_cost, 2),
                'total_litres' => round((float) $row->total_litres, 2),
                'avg_km_per_litre' => $row->avg_kpl !== null ? round((float) $row->avg_kpl, 2) : null,
            ])->all();
    }

    private function maintenanceCount(int $companyId, ?int $fleetId, string $status): int
    {
        return MaintenanceSchedule::query()
            ->whereIn('vehicle_id', Vehicle::query()
                ->where('company_id', $companyId)
                ->when($fleetId, fn ($q) => $q->where('fleet_id', $fleetId))
                ->select('id'))
            ->where('status', $status)
            ->count();
    }

    /**
     * Utilisation = share of active vehicles that recorded distance this
     * month. A vehicle sitting idle still costs insurance and depreciation,
     * so this is the metric fleet managers act on first.
     */
    private function utilisation(int $companyId, ?int $fleetId): array
    {
        $vehicleIds = Vehicle::query()
            ->where('company_id', $companyId)
            ->when($fleetId, fn ($q) => $q->where('fleet_id', $fleetId))
            ->active()
            ->pluck('id');

        if ($vehicleIds->isEmpty()) {
            return ['active_vehicles' => 0, 'utilised' => 0, 'idle' => 0, 'utilisation_pct' => 0.0];
        }

        $utilised = FuelPurchase::query()
            ->whereIn('vehicle_id', $vehicleIds)
            ->where('purchased_at', '>=', now()->startOfMonth())
            ->distinct('vehicle_id')
            ->count('vehicle_id');

        return [
            'active_vehicles' => $vehicleIds->count(),
            'utilised' => $utilised,
            'idle' => $vehicleIds->count() - $utilised,
            'utilisation_pct' => round(($utilised / $vehicleIds->count()) * 100, 1),
        ];
    }

    /** Aggregate of what users would have overpaid at the median price. */
    private function platformSavings(): array
    {
        $row = FuelPurchase::query()
            ->where('purchased_at', '>=', now()->subDays(30))
            ->selectRaw('COUNT(*) AS fill_ups, SUM(total_cost) AS spend, SUM(litres) AS litres, AVG(price_per_litre) AS avg_paid')
            ->first();

        $marketAverage = (float) (\App\Domain\Pricing\Models\StationPrice::avg('price') ?? 0);
        $litres = (float) $row->litres;

        return [
            'fill_ups_30d' => (int) $row->fill_ups,
            'total_spend_30d' => round((float) $row->spend, 2),
            'avg_price_paid' => $row->avg_paid !== null ? round((float) $row->avg_paid, 4) : null,
            'market_avg_price' => round($marketAverage, 4),
            'estimated_savings_30d' => ($row->avg_paid !== null && $marketAverage > 0)
                ? round(max(($marketAverage - (float) $row->avg_paid) * $litres, 0), 2)
                : 0.0,
        ];
    }
}
