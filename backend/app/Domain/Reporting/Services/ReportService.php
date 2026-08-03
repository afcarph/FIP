<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Services;

use App\Domain\Ai\Models\FraudAlert;
use App\Domain\Expense\Models\FuelPurchase;
use App\Domain\Reporting\Models\ReportDefinition;
use App\Domain\Reporting\Models\ReportRun;
use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;
use App\Jobs\GenerateReport;
use App\Support\Exceptions\DomainException;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Maatwebsite\Excel\Excel;

/**
 * Report generation for PDF, Excel, CSV and JSON.
 *
 * Small result sets render inline; anything above the configured row ceiling
 * is queued so a large annual export cannot tie up an HTTP worker. The two
 * paths share the same builders, so a queued report is byte-identical to an
 * inline one.
 */
final readonly class ReportService
{
    public function __construct(private DashboardService $dashboards) {}

    /**
     * Request a report. Returns a ReportRun which is either already complete
     * (inline) or queued (the client polls or waits for the notification).
     */
    public function request(User $user, string $code, array $params, string $format = 'pdf'): ReportRun
    {
        $definition = ReportDefinition::where('code', $code)->firstOrFail();

        if ($definition->required_permission !== null && ! $user->can($definition->required_permission)) {
            throw new DomainException('You do not have access to this report.', 'report_forbidden', 403);
        }

        if (! in_array($format, config('fip.reports.formats'), true)) {
            throw new DomainException("Unsupported format [{$format}].", 'unsupported_format', 422);
        }

        [$from, $to] = $this->resolvePeriod($params);

        $run = ReportRun::create([
            'report_definition_id' => $definition->getKey(),
            'requested_by' => $user->getKey(),
            'company_id' => $user->company_id,
            'params' => $params,
            'format' => $format,
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'status' => ReportRun::STATUS_QUEUED,
            'expires_at' => now()->addDays((int) config('fip.reports.retention_days')),
        ]);

        $estimatedRows = $this->estimateRows($definition, $user, $from, $to);

        if ($estimatedRows > (int) config('fip.reports.max_rows_sync')) {
            GenerateReport::dispatch($run->getKey())->onQueue('reports');

            return $run;
        }

        return $this->generate($run);
    }

    /** Build the artefact and store it. Called inline or from the queue job. */
    public function generate(ReportRun $run): ReportRun
    {
        $run->update(['status' => ReportRun::STATUS_RUNNING, 'started_at' => now()]);

        try {
            $dataset = $this->buildDataset($run);
            $path = $this->render($run, $dataset);

            $run->update([
                'status' => ReportRun::STATUS_COMPLETED,
                'file_path' => $path,
                'file_size' => Storage::size($path),
                'row_count' => count($dataset['rows'] ?? []),
                'completed_at' => now(),
            ]);
        } catch (\Throwable $e) {
            $run->update([
                'status' => ReportRun::STATUS_FAILED,
                'error_message' => mb_substr($e->getMessage(), 0, 500),
                'completed_at' => now(),
            ]);

            throw $e;
        }

        return $run->refresh();
    }

    // ------------------------------------------------------------ datasets

    /**
     * @return array{title: string, columns: array<string, string>, rows: array, summary: array}
     */
    private function buildDataset(ReportRun $run): array
    {
        $user = $run->requester;
        $from = Carbon::parse($run->period_start)->startOfDay();
        $to = Carbon::parse($run->period_end)->endOfDay();

        return match ($run->definition->code) {
            'fuel_expense_summary' => $this->fuelExpenseSummary($user, $from, $to, $run->params ?? []),
            'fleet_utilisation' => $this->fleetUtilisation($user, $from, $to, $run->params ?? []),
            'price_movement' => $this->priceMovement($from, $to),
            'fraud_register' => $this->fraudRegister($user, $from, $to),
            'station_performance' => $this->stationPerformance($user, $from, $to, $run->params ?? []),
            default => throw new DomainException("No builder for report [{$run->definition->code}].", 'unknown_report', 422),
        };
    }

    private function fuelExpenseSummary(User $user, Carbon $from, Carbon $to, array $params): array
    {
        $rows = FuelPurchase::query()
            ->whereIn('vehicle_id', $this->visibleVehicleIds($user, $params))
            ->betweenPeriod($from, $to)
            ->with(['vehicle', 'station', 'fuelType'])
            ->orderBy('purchased_at')
            ->get()
            ->map(static fn (FuelPurchase $p) => [
                'date' => $p->purchased_at->toDateTimeString(),
                'vehicle' => $p->vehicle?->plate_number,
                'station' => $p->station?->name ?? '—',
                'fuel_type' => $p->fuelType?->name,
                'litres' => $p->litres,
                'price_per_litre' => $p->price_per_litre,
                'total_cost' => $p->total_cost,
                'odometer' => $p->odometer,
                'km_per_litre' => $p->km_per_litre,
                'cost_per_km' => $p->cost_per_km,
            ])->all();

        return [
            'title' => 'Fuel Expense Summary',
            'columns' => [
                'date' => 'Date', 'vehicle' => 'Vehicle', 'station' => 'Station',
                'fuel_type' => 'Fuel', 'litres' => 'Litres', 'price_per_litre' => 'Price/L',
                'total_cost' => 'Total', 'odometer' => 'Odometer', 'km_per_litre' => 'km/L',
                'cost_per_km' => 'Cost/km',
            ],
            'rows' => $rows,
            'summary' => [
                'Fill-ups' => count($rows),
                'Total litres' => round(array_sum(array_column($rows, 'litres')), 2),
                'Total cost' => round(array_sum(array_column($rows, 'total_cost')), 2),
                'Average price/L' => $rows === [] ? 0 : round(array_sum(array_column($rows, 'price_per_litre')) / count($rows), 4),
            ],
        ];
    }

    private function fleetUtilisation(User $user, Carbon $from, Carbon $to, array $params): array
    {
        $rows = Vehicle::query()
            ->where('company_id', $user->company_id)
            ->when($params['fleet_id'] ?? null, fn ($q, $id) => $q->where('fleet_id', $id))
            ->with('fleet')
            ->get()
            ->map(function (Vehicle $vehicle) use ($from, $to): array {
                $stats = $vehicle->fuelPurchases()
                    ->betweenPeriod($from, $to)
                    ->selectRaw('COUNT(*) AS fills, SUM(total_cost) AS cost, SUM(litres) AS litres, SUM(distance_since_last) AS distance')
                    ->first();

                $distance = (float) $stats->distance;

                return [
                    'vehicle' => $vehicle->plate_number,
                    'fleet' => $vehicle->fleet?->name ?? '—',
                    'status' => $vehicle->status,
                    'fill_ups' => (int) $stats->fills,
                    'distance_km' => round($distance, 2),
                    'litres' => round((float) $stats->litres, 2),
                    'total_cost' => round((float) $stats->cost, 2),
                    'cost_per_km' => $distance > 0 ? round((float) $stats->cost / $distance, 4) : null,
                    'km_per_litre' => $vehicle->avg_km_per_litre,
                ];
            })->all();

        return [
            'title' => 'Fleet Utilisation',
            'columns' => [
                'vehicle' => 'Vehicle', 'fleet' => 'Fleet', 'status' => 'Status',
                'fill_ups' => 'Fill-ups', 'distance_km' => 'Distance (km)',
                'litres' => 'Litres', 'total_cost' => 'Total cost',
                'cost_per_km' => 'Cost/km', 'km_per_litre' => 'km/L',
            ],
            'rows' => $rows,
            'summary' => [
                'Vehicles' => count($rows),
                'Total distance (km)' => round(array_sum(array_column($rows, 'distance_km')), 2),
                'Total cost' => round(array_sum(array_column($rows, 'total_cost')), 2),
                'Idle vehicles' => count(array_filter($rows, static fn (array $r) => $r['fill_ups'] === 0)),
            ],
        ];
    }

    private function priceMovement(Carbon $from, Carbon $to): array
    {
        $rows = $this->dashboards->executive()['regional_movement'];

        return [
            'title' => 'Regional Price Movement',
            'columns' => [
                'region_name' => 'Region', 'current_avg' => 'Current avg',
                'previous_avg' => 'Previous avg', 'change' => 'Change', 'change_pct' => 'Change %',
            ],
            'rows' => $rows,
            'summary' => ['Regions' => count($rows), 'Period' => "{$from->toDateString()} – {$to->toDateString()}"],
        ];
    }

    private function fraudRegister(User $user, Carbon $from, Carbon $to): array
    {
        $rows = FraudAlert::query()
            ->where('company_id', $user->company_id)
            ->whereBetween('detected_at', [$from, $to])
            ->with(['vehicle', 'driver'])
            ->orderByDesc('detected_at')
            ->get()
            ->map(static fn ($alert) => [
                'detected_at' => $alert->detected_at->toDateTimeString(),
                'type' => $alert->alert_type,
                'severity' => $alert->severity,
                'score' => $alert->score,
                'vehicle' => $alert->vehicle?->plate_number,
                'driver' => $alert->driver?->full_name,
                'status' => $alert->status,
                'resolution' => $alert->resolution_note,
            ])->all();

        return [
            'title' => 'Fraud Alert Register',
            'columns' => [
                'detected_at' => 'Detected', 'type' => 'Type', 'severity' => 'Severity',
                'score' => 'Score', 'vehicle' => 'Vehicle', 'driver' => 'Driver',
                'status' => 'Status', 'resolution' => 'Resolution',
            ],
            'rows' => $rows,
            'summary' => [
                'Alerts' => count($rows),
                'Confirmed' => count(array_filter($rows, static fn (array $r) => $r['status'] === 'confirmed')),
                'Open' => count(array_filter($rows, static fn (array $r) => $r['status'] === 'open')),
            ],
        ];
    }

    private function stationPerformance(User $user, Carbon $from, Carbon $to, array $params): array
    {
        $stations = $user->managedStations()->with(['prices.fuelType', 'brand'])->get();

        $rows = $stations->flatMap(static fn ($station) => $station->prices->map(static fn ($price) => [
            'station' => $station->name,
            'fuel_type' => $price->fuelType?->name,
            'price' => (float) $price->price,
            'previous_price' => $price->previous_price !== null ? (float) $price->previous_price : null,
            'change' => (float) $price->change_amount,
            'source' => $price->source,
            'effective_at' => $price->effective_at->toDateTimeString(),
        ]))->all();

        return [
            'title' => 'Station Performance',
            'columns' => [
                'station' => 'Station', 'fuel_type' => 'Fuel', 'price' => 'Current price',
                'previous_price' => 'Previous', 'change' => 'Change', 'source' => 'Source',
                'effective_at' => 'Effective',
            ],
            'rows' => $rows,
            'summary' => ['Stations' => $stations->count(), 'Price rows' => count($rows)],
        ];
    }

    // ------------------------------------------------------------ rendering

    private function render(ReportRun $run, array $dataset): string
    {
        $filename = sprintf(
            'reports/%s/%s-%s.%s',
            now()->format('Y/m'),
            $run->definition->code,
            $run->getKey(),
            $run->format,
        );

        $contents = match ($run->format) {
            'pdf' => Pdf::loadView('reports.generic', [
                'dataset' => $dataset,
                'run' => $run,
                'generatedAt' => now(),
            ])->setPaper('a4', 'landscape')->output(),
            'csv' => $this->toCsv($dataset),
            'json' => json_encode($dataset, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR),
            'xlsx' => $this->toXlsx($dataset),
            default => throw new DomainException("Unsupported format [{$run->format}].", 'unsupported_format', 422),
        };

        Storage::put($filename, $contents, 'private');

        return $filename;
    }

    private function toCsv(array $dataset): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, array_values($dataset['columns']));

        foreach ($dataset['rows'] as $row) {
            fputcsv($handle, array_map(
                static fn (string $key) => $row[$key] ?? '',
                array_keys($dataset['columns']),
            ));
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        // UTF-8 BOM so Excel opens the peso sign correctly.
        return "\xEF\xBB\xBF".$csv;
    }

    private function toXlsx(array $dataset): string
    {
        $export = new ArrayExport($dataset);

        return \Maatwebsite\Excel\Facades\Excel::raw($export, Excel::XLSX);
    }

    // ------------------------------------------------------------ internals

    /** @return array{0: Carbon, 1: Carbon} */
    private function resolvePeriod(array $params): array
    {
        if (! empty($params['from']) && ! empty($params['to'])) {
            return [Carbon::parse($params['from']), Carbon::parse($params['to'])];
        }

        return match ($params['period'] ?? 'monthly') {
            'daily' => [now()->startOfDay(), now()->endOfDay()],
            'weekly' => [now()->startOfWeek(), now()->endOfWeek()],
            'annual' => [now()->startOfYear(), now()->endOfYear()],
            default => [now()->startOfMonth(), now()->endOfMonth()],
        };
    }

    private function visibleVehicleIds(User $user, array $params)
    {
        return Vehicle::query()
            ->forUser($user)
            ->when($params['vehicle_id'] ?? null, fn ($q, $id) => $q->where('id', $id))
            ->when($params['fleet_id'] ?? null, fn ($q, $id) => $q->where('fleet_id', $id))
            ->select('id');
    }

    private function estimateRows(ReportDefinition $definition, User $user, Carbon $from, Carbon $to): int
    {
        return match ($definition->code) {
            'fuel_expense_summary' => FuelPurchase::query()
                ->whereIn('vehicle_id', $this->visibleVehicleIds($user, []))
                ->betweenPeriod($from, $to)
                ->count(),
            'fleet_utilisation' => Vehicle::where('company_id', $user->company_id)->count(),
            default => 0,
        };
    }
}
