<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Station\Models\GasStation;
use App\Domain\User\Models\User;
use App\Support\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A crowd-sourced submission: a price, a shortage, a closure or a queue
 * report. Moderation state lives here; approved price reports are promoted
 * into `station_prices` by PriceService.
 */
class PriceReport extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_AUTO_APPROVED = 'auto_approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_FLAGGED = 'flagged';

    protected $fillable = [
        'station_id', 'fuel_type_id', 'user_id', 'report_type', 'price', 'photo_path',
        'comment', 'latitude', 'longitude', 'distance_m', 'trust_score', 'status',
        'moderated_by', 'moderated_at', 'rejection_reason',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'latitude' => 'float',
            'longitude' => 'float',
            'distance_m' => 'integer',
            'trust_score' => 'float',
            'moderated_at' => 'datetime',
            'upvotes' => 'integer',
            'downvotes' => 'integer',
        ];
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_PENDING, self::STATUS_FLAGGED]);
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_APPROVED, self::STATUS_AUTO_APPROVED]);
    }

    public function isPublished(): bool
    {
        return in_array($this->status, [self::STATUS_APPROVED, self::STATUS_AUTO_APPROVED], true);
    }

    /** Net community score, used to surface or bury a pending report. */
    public function communityScore(): int
    {
        return $this->upvotes - $this->downvotes;
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(GasStation::class, 'station_id');
    }

    public function fuelType(): BelongsTo
    {
        return $this->belongsTo(FuelType::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function votes(): HasMany
    {
        return $this->hasMany(PriceReportVote::class);
    }
}
