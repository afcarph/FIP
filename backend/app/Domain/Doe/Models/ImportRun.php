<?php

declare(strict_types=1);

namespace App\Domain\Doe\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One execution of the ingest service, for the admin dashboard.
 *
 * @property int $id
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property float|null $duration_seconds
 * @property int $pdfs_discovered
 * @property int $reports_discovered
 * @property int $reports_imported
 * @property int $reports_rejected
 * @property int $records_imported
 * @property int|null $discovery_duration_ms
 * @property int|null $download_duration_ms
 * @property int|null $extraction_duration_ms
 * @property int|null $validation_duration_ms
 * @property int|null $import_duration_ms
 * @property int|null $total_duration_ms
 * @property string|null $parser_versions
 * @property int|null $graphql_pages
 * @property string $status
 * @property string|null $errors
 */
class ImportRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_FAILED = 'failed';

    /** Ran cleanly; the DOE published nothing new. */
    public const STATUS_NO_CHANGES = 'no_changes';

    protected $table = 'doe_import_runs';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'duration_seconds' => 'float',
            'pdfs_discovered' => 'integer',
            'pdfs_downloaded' => 'integer',
            'reports_imported' => 'integer',
            'reports_skipped' => 'integer',
            'records_imported' => 'integer',
            'records_updated' => 'integer',
            'reports_discovered' => 'integer',
            'reports_rejected' => 'integer',
            'discovery_duration_ms' => 'integer',
            'download_duration_ms' => 'integer',
            'extraction_duration_ms' => 'integer',
            'validation_duration_ms' => 'integer',
            'import_duration_ms' => 'integer',
            'total_duration_ms' => 'integer',
            'graphql_pages' => 'integer',
        ];
    }

    /**
     * Per-phase wall clock, in milliseconds, for the phases this run measured.
     *
     * Absent phases are omitted rather than zeroed: a run written before the
     * timings existed measured nothing, and reporting that as 0ms would say
     * the phase was instant.
     *
     * @return array<string, int>
     */
    public function phaseTimings(): array
    {
        $phases = [
            'discovery' => $this->discovery_duration_ms,
            'download' => $this->download_duration_ms,
            'extraction' => $this->extraction_duration_ms,
            'validation' => $this->validation_duration_ms,
            'import' => $this->import_duration_ms,
        ];

        return array_filter($phases, static fn (?int $value): bool => $value !== null);
    }

    /**
     * Which extractor read how many reports, as recorded by the ingest.
     *
     * @return array<string, int>
     */
    public function parserVersions(): array
    {
        if ($this->parser_versions === null || $this->parser_versions === '') {
            return [];
        }

        $decoded = json_decode($this->parser_versions, true);

        // Malformed JSON is a bug in the writer, not a reason to fail a
        // dashboard that exists to show when something is wrong.
        return is_array($decoded) ? $decoded : [];
    }

    /** Whether this run needs someone to look at it. */
    public function isHealthy(): bool
    {
        return in_array($this->status, [
            self::STATUS_SUCCESS,
            self::STATUS_NO_CHANGES,
        ], true);
    }
}
