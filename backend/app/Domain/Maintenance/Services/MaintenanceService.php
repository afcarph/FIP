<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Services;

use App\Domain\Maintenance\Models\MaintenanceRecord;
use App\Domain\Maintenance\Models\MaintenanceSchedule;
use App\Domain\Maintenance\Models\MaintenanceType;
use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;
use App\Services\External\AiServiceClient;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Maintenance scheduling, both rule-based and predictive.
 *
 * The rule engine works from two intervals — calendar and odometer — and the
 * earlier of the two governs. The predictive layer refines that with the
 * vehicle's actual duty cycle: a van covering 4,000 km a month reaches its
 * oil change in six weeks, not the six months the calendar rule assumes.
 */
final readonly class MaintenanceService
{
    public function __construct(private AiServiceClient $ai) {}

    /** Give a new vehicle a full schedule from the maintenance-type defaults. */
    public function bootstrapSchedule(Vehicle $vehicle): int
    {
        $created = 0;

        foreach (MaintenanceType::all() as $type) {
            if ($type->default_interval_km === null && $type->default_interval_days === null) {
                continue;
            }

            $schedule = MaintenanceSchedule::firstOrNew([
                'vehicle_id' => $vehicle->getKey(),
                'maintenance_type_id' => $type->getKey(),
            ]);

            if ($schedule->exists) {
                continue;
            }

            $schedule->fill([
                'interval_km' => $type->default_interval_km,
                'interval_days' => $type->default_interval_days,
                'last_odometer' => $vehicle->current_odometer,
                'last_performed_at' => null,
                'due_odometer' => $type->default_interval_km !== null
                    ? $vehicle->current_odometer + $type->default_interval_km
                    : null,
                'due_at' => $type->default_interval_days !== null
                    ? now()->addDays($type->default_interval_days)->toDateString()
                    : null,
                'status' => MaintenanceSchedule::STATUS_SCHEDULED,
            ])->save();

            $created++;
        }

        // Legal renewals come from the vehicle's own document dates.
        $this->syncLegalSchedules($vehicle);

        return $created;
    }

    /** Log completed work and roll the schedule forward. */
    public function recordService(Vehicle $vehicle, User $user, array $data): MaintenanceRecord
    {
        return DB::transaction(function () use ($vehicle, $user, $data): MaintenanceRecord {
            $record = MaintenanceRecord::create([
                'vehicle_id' => $vehicle->getKey(),
                'maintenance_type_id' => $data['maintenance_type_id'],
                'performed_at' => $data['performed_at'],
                'odometer' => $data['odometer'] ?? $vehicle->current_odometer,
                'cost' => $data['cost'] ?? null,
                'vendor' => $data['vendor'] ?? null,
                'invoice_path' => $data['invoice_path'] ?? null,
                'notes' => $data['notes'] ?? null,
                'recorded_by' => $user->getKey(),
            ]);

            $schedule = MaintenanceSchedule::firstOrNew([
                'vehicle_id' => $vehicle->getKey(),
                'maintenance_type_id' => $data['maintenance_type_id'],
            ]);

            $type = MaintenanceType::find($data['maintenance_type_id']);

            $schedule->fill([
                'interval_km' => $schedule->interval_km ?? $type?->default_interval_km,
                'interval_days' => $schedule->interval_days ?? $type?->default_interval_days,
                'last_performed_at' => $record->performed_at,
                'last_odometer' => $record->odometer,
                'status' => MaintenanceSchedule::STATUS_SCHEDULED,
            ])->save();

            $schedule->recalculate($vehicle->current_odometer);

            return $record;
        });
    }

    /**
     * Refresh the derived status of every schedule for a vehicle. Run after
     * odometer updates and nightly for the whole fleet.
     */
    public function refreshStatuses(Vehicle $vehicle): int
    {
        $touched = 0;

        foreach ($vehicle->maintenanceSchedules()->whereNot('status', MaintenanceSchedule::STATUS_COMPLETED)->get() as $schedule) {
            $schedule->recalculate($vehicle->current_odometer);
            $touched++;
        }

        $this->syncLegalSchedules($vehicle);

        return $touched;
    }

    /**
     * Ask the model when each service item will *actually* fall due given the
     * vehicle's recent usage, and store the estimate alongside the rule-based
     * date. The rule-based date remains the contractual one; the prediction
     * is advisory and is what drives early reminders.
     */
    public function predictUpcoming(Vehicle $vehicle): array
    {
        $schedules = $vehicle->maintenanceSchedules()->with('type')->get();

        if ($schedules->isEmpty()) {
            return [];
        }

        try {
            $response = $this->ai->predictMaintenance([
                'vehicle' => [
                    'id' => $vehicle->getKey(),
                    'type' => $vehicle->vehicle_type,
                    'year' => $vehicle->year,
                    'odometer' => $vehicle->current_odometer,
                    'avg_km_per_litre' => $vehicle->avg_km_per_litre,
                    'baseline_km_per_litre' => $vehicle->baseline_km_per_litre,
                ],
                'usage' => $this->usageProfile($vehicle),
                'schedules' => $schedules->map(static fn (MaintenanceSchedule $s) => [
                    'id' => $s->getKey(),
                    'code' => $s->type?->code,
                    'interval_km' => $s->interval_km,
                    'interval_days' => $s->interval_days,
                    'last_performed_at' => $s->last_performed_at?->toDateString(),
                    'last_odometer' => $s->last_odometer,
                    'due_at' => $s->due_at?->toDateString(),
                    'due_odometer' => $s->due_odometer,
                ])->all(),
                'history' => $vehicle->maintenanceRecords()
                    ->with('type')
                    ->latest('performed_at')
                    ->limit(50)
                    ->get()
                    ->map(static fn (MaintenanceRecord $r) => [
                        'code' => $r->type?->code,
                        'performed_at' => $r->performed_at->toDateString(),
                        'odometer' => $r->odometer,
                        'cost' => $r->cost,
                    ])->all(),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Maintenance prediction unavailable', [
                'vehicle_id' => $vehicle->getKey(),
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        $applied = [];

        foreach ($response['predictions'] ?? [] as $prediction) {
            $schedule = $schedules->firstWhere('id', $prediction['schedule_id'] ?? null);

            if ($schedule === null || empty($prediction['predicted_due_at'])) {
                continue;
            }

            $schedule->forceFill([
                'predicted_due_at' => Carbon::parse($prediction['predicted_due_at'])->toDateString(),
                'prediction_confidence' => round((float) ($prediction['confidence'] ?? 0), 3),
            ])->save();

            $applied[] = [
                'schedule_id' => $schedule->getKey(),
                'service' => $schedule->type?->name,
                'predicted_due_at' => $schedule->predicted_due_at?->toDateString(),
                'rule_based_due_at' => $schedule->due_at?->toDateString(),
                'confidence' => $schedule->prediction_confidence,
                'reason' => $prediction['reason'] ?? null,
            ];
        }

        return $applied;
    }

    /** Everything due (or predicted due) for a vehicle inside a window. */
    public function upcomingFor(Vehicle $vehicle, int $days = 30): array
    {
        return $vehicle->maintenanceSchedules()
            ->with('type')
            ->where(fn ($q) => $q
                ->dueWithin($days)
                ->orWhere(fn ($inner) => $inner
                    ->whereNotNull('predicted_due_at')
                    ->where('predicted_due_at', '<=', now()->addDays($days)->toDateString())))
            ->get()
            ->map(fn (MaintenanceSchedule $s) => [
                'id' => $s->getKey(),
                'service' => $s->type?->name,
                'category' => $s->type?->category,
                'icon' => $s->type?->icon,
                'status' => $s->status,
                'due_at' => $s->due_at?->toDateString(),
                'due_odometer' => $s->due_odometer,
                'km_remaining' => $s->kilometresRemaining($vehicle->current_odometer),
                'predicted_due_at' => $s->predicted_due_at?->toDateString(),
                'prediction_confidence' => $s->prediction_confidence,
            ])
            ->sortBy(fn (array $row) => $row['predicted_due_at'] ?? $row['due_at'] ?? '9999-12-31')
            ->values()
            ->all();
    }

    // ------------------------------------------------------------ internals

    /** Recent duty cycle: how hard is this vehicle actually being worked? */
    private function usageProfile(Vehicle $vehicle): array
    {
        $since = now()->subDays(90);

        $row = $vehicle->fuelPurchases()
            ->where('purchased_at', '>=', $since)
            ->selectRaw('
                COALESCE(SUM(distance_since_last), 0) AS distance,
                COUNT(*) AS fill_ups,
                AVG(km_per_litre) AS avg_kpl
            ')
            ->first();

        $days = max($since->diffInDays(now()), 1);

        return [
            'window_days' => $days,
            'distance_km' => round((float) $row->distance, 2),
            'avg_km_per_day' => round((float) $row->distance / $days, 2),
            'fill_ups' => (int) $row->fill_ups,
            'avg_km_per_litre' => $row->avg_kpl !== null ? round((float) $row->avg_kpl, 2) : null,
        ];
    }

    /** Registration and insurance renewals mirror the vehicle's own dates. */
    private function syncLegalSchedules(Vehicle $vehicle): void
    {
        $map = [
            'registration' => $vehicle->registration_expiry,
            'insurance' => $vehicle->insurance_expiry,
        ];

        foreach ($map as $code => $expiry) {
            if ($expiry === null) {
                continue;
            }

            $type = MaintenanceType::where('code', $code)->first();

            if ($type === null) {
                continue;
            }

            $schedule = MaintenanceSchedule::firstOrNew([
                'vehicle_id' => $vehicle->getKey(),
                'maintenance_type_id' => $type->getKey(),
            ]);

            $schedule->fill([
                'interval_days' => 365,
                'due_at' => $expiry->toDateString(),
                'status' => $expiry->isPast()
                    ? MaintenanceSchedule::STATUS_OVERDUE
                    : ($expiry->lte(now()->addDays((int) config('fip.maintenance.due_soon_days')))
                        ? MaintenanceSchedule::STATUS_DUE_SOON
                        : MaintenanceSchedule::STATUS_SCHEDULED),
            ])->save();
        }
    }
}
