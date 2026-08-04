<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Models;

use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $report_definition_id
 * @property int $requested_by
 * @property int|null $company_id
 * @property array<array-key, mixed>|null $params
 * @property string $format
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property string $status
 * @property string|null $file_path
 * @property int|null $file_size
 * @property int|null $row_count
 * @property string|null $error_message
 * @property Carbon|null $started_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Company|null $company
 * @property-read ReportDefinition $definition
 * @property-read User|null $requester
 *
 * @method static Builder<static>|ReportRun downloadable()
 * @method static Builder<static>|ReportRun newModelQuery()
 * @method static Builder<static>|ReportRun newQuery()
 * @method static Builder<static>|ReportRun query()
 * @method static Builder<static>|ReportRun whereCompanyId($value)
 * @method static Builder<static>|ReportRun whereCompletedAt($value)
 * @method static Builder<static>|ReportRun whereCreatedAt($value)
 * @method static Builder<static>|ReportRun whereErrorMessage($value)
 * @method static Builder<static>|ReportRun whereExpiresAt($value)
 * @method static Builder<static>|ReportRun whereFilePath($value)
 * @method static Builder<static>|ReportRun whereFileSize($value)
 * @method static Builder<static>|ReportRun whereFormat($value)
 * @method static Builder<static>|ReportRun whereId($value)
 * @method static Builder<static>|ReportRun whereParams($value)
 * @method static Builder<static>|ReportRun wherePeriodEnd($value)
 * @method static Builder<static>|ReportRun wherePeriodStart($value)
 * @method static Builder<static>|ReportRun whereReportDefinitionId($value)
 * @method static Builder<static>|ReportRun whereRequestedBy($value)
 * @method static Builder<static>|ReportRun whereRowCount($value)
 * @method static Builder<static>|ReportRun whereStartedAt($value)
 * @method static Builder<static>|ReportRun whereStatus($value)
 * @method static Builder<static>|ReportRun whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class ReportRun extends Model
{
    public const STATUS_QUEUED = 'queued';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'report_definition_id', 'requested_by', 'company_id', 'params', 'format',
        'period_start', 'period_end', 'status', 'file_path', 'file_size',
        'row_count', 'error_message', 'started_at', 'completed_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'params' => 'array',
            'period_start' => 'date',
            'period_end' => 'date',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'file_size' => 'integer',
            'row_count' => 'integer',
        ];
    }

    public function scopeDownloadable(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    /** Short-lived signed URL; report files live in a private bucket. */
    public function downloadUrl(int $minutes = 15): ?string
    {
        return $this->file_path === null ? null : Storage::temporaryUrl($this->file_path, now()->addMinutes($minutes));
    }

    public function definition(): BelongsTo
    {
        return $this->belongsTo(ReportDefinition::class, 'report_definition_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
