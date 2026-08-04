<?php

declare(strict_types=1);

namespace App\Domain\Station\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $province_id
 * @property string $code
 * @property string $name
 * @property bool $is_city
 * @property float|null $latitude
 * @property float|null $longitude
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Province $province
 * @property-read Region|null $region
 * @property-read Collection<int, GasStation> $stations
 * @property-read int|null $stations_count
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereCode($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereIsCity($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereLatitude($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereLongitude($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereProvinceId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|City whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class City extends Model
{
    protected $fillable = ['province_id', 'code', 'name', 'is_city', 'latitude', 'longitude'];

    protected function casts(): array
    {
        return ['is_city' => 'boolean', 'latitude' => 'float', 'longitude' => 'float'];
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function stations(): HasMany
    {
        return $this->hasMany(GasStation::class);
    }

    public function region(): BelongsTo
    {
        return $this->province()->getRelated()->region();
    }
}
