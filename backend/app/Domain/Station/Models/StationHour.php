<?php

declare(strict_types=1);

namespace App\Domain\Station\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $station_id
 * @property int $day_of_week
 * @property string|null $opens_at
 * @property string|null $closes_at
 * @property bool $is_closed
 * @property-read GasStation|null $station
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationHour newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationHour newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationHour query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationHour whereClosesAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationHour whereDayOfWeek($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationHour whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationHour whereIsClosed($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationHour whereOpensAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|StationHour whereStationId($value)
 *
 * @mixin \Eloquent
 */
class StationHour extends Model
{
    public $timestamps = false;

    protected $fillable = ['station_id', 'day_of_week', 'opens_at', 'closes_at', 'is_closed'];

    protected function casts(): array
    {
        return ['is_closed' => 'boolean', 'day_of_week' => 'integer'];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(GasStation::class, 'station_id');
    }
}
