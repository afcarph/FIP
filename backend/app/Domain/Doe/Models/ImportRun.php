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
 * @property int $reports_imported
 * @property int $records_imported
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
        ];
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
