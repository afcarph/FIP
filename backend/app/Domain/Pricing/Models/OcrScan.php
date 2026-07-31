<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Station\Models\GasStation;
use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * One photograph of a station price board, plus whatever the OCR pipeline
 * extracted from it.
 */
class OcrScan extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_PROCESSING = 'processing';
    public const STATUS_PARSED = 'parsed';
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id', 'station_id', 'image_path', 'raw_text', 'parsed_payload',
        'engine', 'overall_confidence', 'status', 'reviewed_by', 'reviewed_at', 'error_message',
    ];

    protected function casts(): array
    {
        return [
            'parsed_payload' => 'array',
            'overall_confidence' => 'float',
            'reviewed_at' => 'datetime',
        ];
    }

    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_NEEDS_REVIEW, self::STATUS_PARSED]);
    }

    /** Rows the reviewer can accept as-is because the engine was confident. */
    public function confidentLines(): array
    {
        $threshold = (float) config('fip.ocr.min_confidence');

        return array_values(array_filter(
            $this->parsed_payload ?? [],
            static fn (array $line) => ($line['confidence'] ?? 0) >= $threshold,
        ));
    }

    public function getImageUrlAttribute(): ?string
    {
        return $this->image_path === null ? null : Storage::temporaryUrl($this->image_path, now()->addHour());
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(GasStation::class, 'station_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
