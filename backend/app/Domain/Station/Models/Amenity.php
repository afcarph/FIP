<?php

declare(strict_types=1);

namespace App\Domain\Station\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $icon
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, GasStation> $stations
 * @property-read int|null $stations_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Amenity newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Amenity newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Amenity query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Amenity whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Amenity whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Amenity whereIcon($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Amenity whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Amenity whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|Amenity whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class Amenity extends Model
{
    protected $fillable = ['code', 'name', 'icon'];

    public function stations(): BelongsToMany
    {
        return $this->belongsToMany(GasStation::class, 'station_amenity', 'amenity_id', 'station_id');
    }
}
