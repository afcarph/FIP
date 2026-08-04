<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Station\Models\GasStation;
use App\Domain\User\Models\User;
use App\Support\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A crowd-sourced submission: a price, a shortage, a closure or a queue
 * report. Moderation state lives here; approved price reports are promoted
 * into `station_prices` by PriceService.
 *
 * @property int $id
 * @property int $station_id
 * @property int|null $fuel_type_id
 * @property int $user_id
 * @property string $report_type
 * @property numeric|null $price
 * @property string|null $photo_path
 * @property string|null $comment
 * @property float|null $latitude
 * @property float|null $longitude
 * @property int|null $distance_m
 * @property float $trust_score
 * @property string $status
 * @property int|null $moderated_by
 * @property Carbon|null $moderated_at
 * @property string|null $rejection_reason
 * @property int $upvotes
 * @property int $downvotes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read FuelType|null $fuelType
 * @property-read User|null $moderator
 * @property-read GasStation|null $station
 * @property-read User|null $user
 * @property-read Collection<int, PriceReportVote> $votes
 * @property-read int|null $votes_count
 *
 * @method static \Database\Factories\PriceReportFactory factory($count = null, $state = [])
 * @method static Builder<static>|PriceReport newModelQuery()
 * @method static Builder<static>|PriceReport newQuery()
 * @method static Builder<static>|PriceReport onlyTrashed()
 * @method static Builder<static>|PriceReport pending()
 * @method static Builder<static>|PriceReport published()
 * @method static Builder<static>|PriceReport query()
 * @method static Builder<static>|PriceReport whereComment($value)
 * @method static Builder<static>|PriceReport whereCreatedAt($value)
 * @method static Builder<static>|PriceReport whereDeletedAt($value)
 * @method static Builder<static>|PriceReport whereDistanceM($value)
 * @method static Builder<static>|PriceReport whereDownvotes($value)
 * @method static Builder<static>|PriceReport whereFuelTypeId($value)
 * @method static Builder<static>|PriceReport whereId($value)
 * @method static Builder<static>|PriceReport whereLatitude($value)
 * @method static Builder<static>|PriceReport whereLongitude($value)
 * @method static Builder<static>|PriceReport whereModeratedAt($value)
 * @method static Builder<static>|PriceReport whereModeratedBy($value)
 * @method static Builder<static>|PriceReport wherePhotoPath($value)
 * @method static Builder<static>|PriceReport wherePrice($value)
 * @method static Builder<static>|PriceReport whereRejectionReason($value)
 * @method static Builder<static>|PriceReport whereReportType($value)
 * @method static Builder<static>|PriceReport whereStationId($value)
 * @method static Builder<static>|PriceReport whereStatus($value)
 * @method static Builder<static>|PriceReport whereTrustScore($value)
 * @method static Builder<static>|PriceReport whereUpdatedAt($value)
 * @method static Builder<static>|PriceReport whereUpvotes($value)
 * @method static Builder<static>|PriceReport whereUserId($value)
 * @method static Builder<static>|PriceReport withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|PriceReport withoutTrashed()
 *
 * @mixin \Eloquent
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
