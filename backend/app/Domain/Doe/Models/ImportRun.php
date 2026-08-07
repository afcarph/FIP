<?php

declare(strict_types=1);

namespace App\Domain\Doe\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
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
            // started_at and finished_at are deliberately absent — see the
            // accessors below. Casting them as `datetime` reads them in the
            // application timezone, and the ingest writes them in UTC.
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
     * Read as UTC, because that is how they are written.
     *
     * The Python ingest stores naive UTC in these columns — MySQL DATETIME
     * carries no zone, so an aware value would read back naive anyway. The
     * default `datetime` cast interprets the same digits in the application
     * timezone, Asia/Manila, which moved every run eight hours into the past:
     * a run 56 minutes old was reported as 8 hours old, and the staleness
     * check that guards against a dead scheduler would have fired eight hours
     * early — or, in the other direction, held its alarm eight hours too long.
     */
    protected function startedAt(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $value): ?Carbon => $value === null
                ? null
                : Carbon::parse($value, 'UTC'),
        );
    }

    protected function finishedAt(): Attribute
    {
        return Attribute::make(
            get: static fn (?string $value): ?Carbon => $value === null
                ? null
                : Carbon::parse($value, 'UTC'),
        );
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
