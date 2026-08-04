<?php

declare(strict_types=1);

namespace App\Domain\Station\Models;

use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $station_id
 * @property int $user_id
 * @property int $rating
 * @property string|null $comment
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read GasStation|null $station
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationRating newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationRating newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationRating onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationRating query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationRating whereComment($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationRating whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationRating whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationRating whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationRating whereRating($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationRating whereStationId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationRating whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationRating whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationRating withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationRating withoutTrashed()
 *
 * @mixin \Eloquent
 */
class StationRating extends Model
{
    use SoftDeletes;

    protected $fillable = ['station_id', 'user_id', 'rating', 'comment'];

    protected function casts(): array
    {
        return ['rating' => 'integer'];
    }

    protected static function booted(): void
    {
        // Keep the denormalised aggregate on gas_stations in step.
        static::saved(fn (self $r) => $r->station?->recalculateRating());
        static::deleted(fn (self $r) => $r->station?->recalculateRating());
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(GasStation::class, 'station_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
