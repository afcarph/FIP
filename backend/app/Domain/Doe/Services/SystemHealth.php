<?php

declare(strict_types=1);

namespace App\Domain\Doe\Services;

use App\Domain\Doe\Models\FuelPrice;
use App\Domain\Doe\Models\FuelReport;
use App\Domain\Doe\Models\ImportRun;
use Carbon\CarbonImmutable;
use FilesystemIterator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * What the platform can say about its own condition.
 *
 * Two callers with different needs: a load balancer wants one word and a
 * status code, and an operator wants to know which part is unwell. Both are
 * served from here so they cannot disagree — a dashboard that says "degraded"
 * while the probe returns 200 is worse than either alone.
 *
 * Every check is written to fail closed and say why. A check that throws is
 * reported as failed with its message rather than taking down the endpoint
 * whose job is to report failures.
 */
class SystemHealth
{
    /** Below this share of free disk, the archive stops being able to grow. */
    private const DISK_WARN_FREE_RATIO = 0.10;

    private const DISK_CRITICAL_FREE_RATIO = 0.05;

    /**
     * The readiness answer: is this instance fit to serve traffic?
     *
     * @return array{status: string, checks: array<string, mixed>, time: string}
     */
    public function readiness(): array
    {
        $checks = [
            'database' => $this->database(),
            'scheduler' => $this->scheduler(),
            'disk' => $this->disk(),
            'storage' => $this->storage(),
        ];

        return [
            'status' => $this->worstOf($checks),
            'checks' => $checks,
            'time' => now()->toIso8601String(),
        ];
    }

    /**
     * Everything the operator dashboard shows.
     *
     * @return array<string, mixed>
     */
    public function system(): array
    {
        $lastRun = ImportRun::query()->orderByDesc('started_at')->first();
        $lastGood = ImportRun::query()
            ->whereIn('status', [ImportRun::STATUS_SUCCESS, ImportRun::STATUS_NO_CHANGES])
            ->orderByDesc('started_at')
            ->first();

        // Once, not twice: readiness runs four checks including a directory
        // walk, and calling it per key would double every one of them.
        $readiness = $this->readiness();

        return [
            'status' => $readiness['status'],
            'checks' => $readiness['checks'],
            'discovery' => $this->discovery($lastRun),
            'last_import' => $this->lastImport($lastRun, $lastGood),
            'coverage' => $this->coverage(),
            'parser_versions' => $this->parserVersions(),
            'time' => now()->toIso8601String(),
        ];
    }

    // -- individual checks ----------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function database(): array
    {
        $started = microtime(true);

        try {
            // A connection that opens but cannot answer is not ready, so this
            // issues a real query rather than trusting the pool.
            DB::connection()->select('select 1');
            $latency = (int) round((microtime(true) - $started) * 1000);

            // Reads the table the API actually serves from. A database that
            // answers `select 1` while the schema is half-migrated would pass
            // a shallower check and fail every request.
            $reports = FuelReport::query()->count();

            return [
                'status' => $latency > 1000 ? 'degraded' : 'ok',
                'latency_ms' => $latency,
                'driver' => DB::connection()->getDriverName(),
                'reports' => $reports,
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'down',
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduler(): array
    {
        try {
            $lastRun = ImportRun::query()->orderByDesc('started_at')->first();

            if ($lastRun === null) {
                return [
                    'status' => 'down',
                    'detail' => 'No ingest run has ever been recorded.',
                ];
            }

            $staleAfter = (int) config('doe.scheduler_stale_after_hours', 26);
            $hours = $lastRun->started_at->diffInHours(now());
            $stale = $hours > $staleAfter;

            return [
                // Deliberately not keyed on the run's own status. A run that
                // imported nothing because nothing was published is healthy;
                // a scheduler that has not fired since Tuesday is not, however
                // green its last run looked.
                'status' => $stale ? 'down' : 'ok',
                'last_run_at' => $lastRun->started_at->toIso8601String(),
                'hours_since_last_run' => (int) $hours,
                'stale_after_hours' => $staleAfter,
                'last_run_status' => $lastRun->status,
            ];
        } catch (Throwable $exception) {
            return ['status' => 'down', 'error' => $exception->getMessage()];
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function disk(): array
    {
        try {
            $path = storage_path();
            $free = @disk_free_space($path);
            $total = @disk_total_space($path);

            if ($free === false || $total === false || $total <= 0) {
                return ['status' => 'degraded', 'detail' => 'Disk usage is not reportable here.'];
            }

            $ratio = $free / $total;

            return [
                'status' => match (true) {
                    $ratio < self::DISK_CRITICAL_FREE_RATIO => 'down',
                    $ratio < self::DISK_WARN_FREE_RATIO => 'degraded',
                    default => 'ok',
                },
                'free_bytes' => (int) $free,
                'total_bytes' => (int) $total,
                'used_percent' => round((1 - $ratio) * 100, 1),
                'path' => $path,
            ];
        } catch (Throwable $exception) {
            return ['status' => 'degraded', 'error' => $exception->getMessage()];
        }
    }

    /**
     * Size of the PDF archive.
     *
     * Cached for a minute: this walks the archive directory, and a health
     * endpoint that a load balancer polls every few seconds must not do that
     * on every request.
     *
     * @return array<string, mixed>
     */
    private function storage(): array
    {
        try {
            $path = (string) config('doe.pdf_archive_path', storage_path('app/doe-pdfs'));

            /** @var array{files: int, bytes: int}|null $usage */
            $usage = Cache::remember(
                'doe:storage-usage',
                now()->addMinute(),
                fn (): array => $this->measureDirectory($path),
            );

            return [
                // The archive being absent is not a failure on a fresh
                // instance, and is a failure on one that has been importing
                // for a month. This cannot tell the two apart, so it reports
                // and does not judge.
                'status' => 'ok',
                'path' => $path,
                'exists' => is_dir($path),
                'files' => $usage['files'],
                'bytes' => $usage['bytes'],
            ];
        } catch (Throwable $exception) {
            return ['status' => 'degraded', 'error' => $exception->getMessage()];
        }
    }

    /**
     * @return array{files: int, bytes: int}
     */
    private function measureDirectory(string $path): array
    {
        if (! is_dir($path)) {
            return ['files' => 0, 'bytes' => 0];
        }

        $files = 0;
        $bytes = 0;

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $files++;
                $bytes += $file->getSize();
            }
        }

        return ['files' => $files, 'bytes' => $bytes];
    }

    // -- dashboard sections ---------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function discovery(?ImportRun $lastRun): array
    {
        return [
            'provider' => 'graphql',
            'endpoint' => (string) config('doe.graphql_endpoint', 'https://prod-cms.doe.gov.ph/o/graphql'),
            // Whether the last run reached the CMS at all. Documents
            // discovered is the evidence: the query cannot return candidates
            // from an endpoint it could not talk to.
            'status' => match (true) {
                $lastRun === null => 'unknown',
                $lastRun->pdfs_discovered > 0 => 'ok',
                default => 'degraded',
            },
            'documents_discovered' => $lastRun?->pdfs_discovered,
            'pages_walked' => $lastRun?->graphql_pages,
            'reports_parsed' => $lastRun?->reports_discovered,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function lastImport(?ImportRun $lastRun, ?ImportRun $lastGood): array
    {
        if ($lastRun === null) {
            return ['status' => 'unknown'];
        }

        return [
            'status' => $lastRun->status,
            'healthy' => $lastRun->isHealthy(),
            'started_at' => $lastRun->started_at->toIso8601String(),
            'finished_at' => $lastRun->finished_at?->toIso8601String(),
            'run_id' => $lastRun->run_id,
            'reports_discovered' => $lastRun->reports_discovered,
            'reports_imported' => $lastRun->reports_imported,
            'reports_skipped' => $lastRun->reports_skipped,
            'reports_rejected' => $lastRun->reports_rejected,
            'rows_imported' => $lastRun->records_imported,
            'rows_updated' => $lastRun->records_updated,
            'total_duration_ms' => $lastRun->total_duration_ms,
            // Empty when the run predates the timings. An absent phase is not
            // a phase that took no time, so nothing is filled in with zero.
            'phase_durations_ms' => $lastRun->phaseTimings(),
            'last_successful_run_at' => $lastGood?->started_at->toIso8601String(),
            'messages' => $this->messages($lastRun),
        ];
    }

    /**
     * @return list<string>
     */
    private function messages(ImportRun $run): array
    {
        if ($run->errors === null || $run->errors === '') {
            return [];
        }

        return array_values(array_filter(
            array_map(trim(...), explode("\n", $run->errors)),
            static fn (string $line): bool => $line !== '',
        ));
    }

    /**
     * What data the platform currently holds, per region.
     *
     * @return array<string, mixed>
     */
    private function coverage(): array
    {
        $latest = FuelReport::query()
            ->selectRaw('region, MAX(coverage_start) as coverage_start, MAX(coverage_end) as coverage_end')
            ->groupBy('region')
            ->orderBy('region')
            ->get();

        $today = CarbonImmutable::now()->startOfDay();

        return [
            'reports_total' => FuelReport::query()->count(),
            'prices_total' => FuelPrice::query()->count(),
            'oldest_coverage_start' => FuelReport::query()->min('coverage_start'),
            'regions' => $latest->map(function ($row) use ($today): array {
                $end = CarbonImmutable::parse($row->coverage_end)->startOfDay();
                // Measured from the end of the covered week, matching the
                // freshness rule the clients use. Two definitions of "how old
                // is this" is one too many.
                $age = max(0, (int) $end->diffInDays($today, false));

                return [
                    'region' => $row->region,
                    'coverage_start' => (string) $row->coverage_start,
                    'coverage_end' => (string) $row->coverage_end,
                    'age_days' => $age,
                    'is_current_week' => $age === 0,
                ];
            })->all(),
        ];
    }

    /**
     * Which extractor read how many reports, over the recent runs.
     *
     * @return array<string, int>
     */
    private function parserVersions(): array
    {
        $totals = [];

        $runs = ImportRun::query()
            ->whereNotNull('parser_versions')
            ->orderByDesc('started_at')
            ->limit(20)
            ->get();

        foreach ($runs as $run) {
            foreach ($run->parserVersions() as $parser => $count) {
                $totals[$parser] = ($totals[$parser] ?? 0) + (int) $count;
            }
        }

        ksort($totals);

        return $totals;
    }

    // -- helpers --------------------------------------------------------------

    /**
     * The worst status any check reported.
     *
     * @param array<string, mixed> $checks
     */
    private function worstOf(array $checks): string
    {
        $rank = ['ok' => 0, 'degraded' => 1, 'down' => 2];
        $worst = 'ok';

        foreach ($checks as $check) {
            $status = is_array($check) ? (string) ($check['status'] ?? 'ok') : 'ok';

            if (($rank[$status] ?? 0) > $rank[$worst]) {
                $worst = $status;
            }
        }

        return $worst;
    }
}
