<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Models;

use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

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
