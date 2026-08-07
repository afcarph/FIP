<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Doe\Models\DoeImportBatch;
use App\Domain\Doe\Models\DoeStationReview;
use App\Domain\Doe\Services\DoeImportService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Import batches the scraper captured.
 *
 *   php artisan fip:doe-import                 # every pending batch
 *   php artisan fip:doe-import --batch=42      # one, whatever its status
 *   php artisan fip:doe-import --replay=42     # re-import from the stored payload
 *   php artisan fip:doe-import --retry-failed  # every batch that failed
 *
 * Replay is the reason the raw payload is kept. It is not only for failures: a
 * parser fix should be re-runnable against the batches it would have got
 * wrong, and by then the dashboard shows a different week. Replaying is safe
 * because PriceService recognises a reading that does not supersede what is
 * stored, so re-importing creates no duplicate.
 */
class ImportDoeBatches extends Command
{
    protected $signature = 'fip:doe-import
        {--batch= : Import one batch by id, whatever its status}
        {--replay= : Re-import a batch from its stored payload}
        {--retry-failed : Import every batch that previously failed}
        {--limit=25 : Most batches to process in one run}';

    protected $description = 'Import captured DOE payloads into the platform price model';

    public function handle(DoeImportService $importer): int
    {
        $batches = $this->resolveBatches();

        if ($batches->isEmpty()) {
            $this->info('Nothing to import.');

            return self::SUCCESS;
        }

        $this->info("Importing {$batches->count()} batch(es).");

        $failures = 0;
        $imported = 0;

        foreach ($batches as $batch) {
            if (! $batch->isReplayable()) {
                $this->warn("Batch {$batch->id} has no stored payload; skipping.");
                $batch->markFailed('No stored payload to import.');
                $failures++;

                continue;
            }

            $this->line("  batch {$batch->id} ({$batch->started_at->toDateTimeString()})");

            $outcome = $importer->import($batch);

            if ($outcome->failed) {
                $this->error("    {$outcome->summary()}");
                $failures++;

                continue;
            }

            $this->line("    <fg=green>{$outcome->summary()}</>");
            $imported += $outcome->imported;
        }

        $this->newLine();

        if ($failures > 0) {
            $this->warn("{$failures} batch(es) failed. Their payloads are kept — re-run with --replay=<id> after a fix.");
        }

        $this->info("Done. {$imported} price(s) recorded.");
        $this->line('  php artisan fip:forecast   # regenerate forecasts from the new history');

        $pending = DoeStationReview::query()->pending()->count();

        if ($pending > 0) {
            $this->warn("{$pending} station(s) awaiting manual mapping in doe_station_review.");
        }

        // Non-zero only when nothing at all landed. A partial import is a
        // normal day — a handful of unmatched stations should not fail a
        // scheduled job and page someone.
        return $imported === 0 && $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return Collection<int, DoeImportBatch>
     */
    private function resolveBatches(): Collection
    {
        $limit = max(1, (int) $this->option('limit'));

        if ($id = $this->option('replay') ?? $this->option('batch')) {
            return DoeImportBatch::query()->whereKey((int) $id)->get();
        }

        if ($this->option('retry-failed')) {
            return DoeImportBatch::query()
                ->where('status', DoeImportBatch::STATUS_FAILED)
                ->orderBy('started_at')
                ->limit($limit)
                ->get();
        }

        return DoeImportBatch::query()
            ->where('status', DoeImportBatch::STATUS_PENDING)
            // Oldest first: prices are a series, and importing Thursday before
            // Tuesday would have PriceService reject Tuesday as stale.
            ->orderBy('started_at')
            ->limit($limit)
            ->get();
    }
}
