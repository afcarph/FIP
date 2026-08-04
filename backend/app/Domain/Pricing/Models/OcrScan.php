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
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * One photograph of a station price board, plus whatever the OCR pipeline
 * extracted from it.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $station_id
 * @property string $image_path
 * @property string|null $raw_text
 * @property array<array-key, mixed>|null $parsed_payload
 * @property string $engine
 * @property float|null $overall_confidence
 * @property string $status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property string|null $error_message
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read string|null $image_url
 * @property-read User|null $reviewer
 * @property-read GasStation|null $station
 * @property-read User|null $user
 *
 * @method static Builder<static>|OcrScan awaitingReview()
 * @method static Builder<static>|OcrScan newModelQuery()
 * @method static Builder<static>|OcrScan newQuery()
 * @method static Builder<static>|OcrScan onlyTrashed()
 * @method static Builder<static>|OcrScan query()
 * @method static Builder<static>|OcrScan whereCreatedAt($value)
 * @method static Builder<static>|OcrScan whereDeletedAt($value)
 * @method static Builder<static>|OcrScan whereEngine($value)
 * @method static Builder<static>|OcrScan whereErrorMessage($value)
 * @method static Builder<static>|OcrScan whereId($value)
 * @method static Builder<static>|OcrScan whereImagePath($value)
 * @method static Builder<static>|OcrScan whereOverallConfidence($value)
 * @method static Builder<static>|OcrScan whereParsedPayload($value)
 * @method static Builder<static>|OcrScan whereRawText($value)
 * @method static Builder<static>|OcrScan whereReviewedAt($value)
 * @method static Builder<static>|OcrScan whereReviewedBy($value)
 * @method static Builder<static>|OcrScan whereStationId($value)
 * @method static Builder<static>|OcrScan whereStatus($value)
 * @method static Builder<static>|OcrScan whereUpdatedAt($value)
 * @method static Builder<static>|OcrScan whereUserId($value)
 * @method static Builder<static>|OcrScan withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|OcrScan withoutTrashed()
 *
 * @mixin \Eloquent
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
