<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $category
 * @property int|null $octane
 * @property string $unit
 * @property string $color_hex
 * @property int $sort_order
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, PriceAdvisory> $advisories
 * @property-read int|null $advisories_count
 * @property-read Collection<int, StationPrice> $prices
 * @property-read int|null $prices_count
 *
 * @method static Builder<static>|FuelType active()
 * @method static Builder<static>|FuelType newModelQuery()
 * @method static Builder<static>|FuelType newQuery()
 * @method static Builder<static>|FuelType query()
 * @method static Builder<static>|FuelType whereCategory($value)
 * @method static Builder<static>|FuelType whereCode($value)
 * @method static Builder<static>|FuelType whereColorHex($value)
 * @method static Builder<static>|FuelType whereCreatedAt($value)
 * @method static Builder<static>|FuelType whereId($value)
 * @method static Builder<static>|FuelType whereIsActive($value)
 * @method static Builder<static>|FuelType whereName($value)
 * @method static Builder<static>|FuelType whereOctane($value)
 * @method static Builder<static>|FuelType whereSortOrder($value)
 * @method static Builder<static>|FuelType whereUnit($value)
 * @method static Builder<static>|FuelType whereUpdatedAt($value)
 *
 * @mixin \Eloquent
 */
class FuelType extends Model
{
    protected $fillable = ['code', 'name', 'category', 'octane', 'unit', 'color_hex', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'octane' => 'integer', 'sort_order' => 'integer'];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->orderBy('sort_order');
    }

    public function prices(): HasMany
    {
        return $this->hasMany(StationPrice::class);
    }

    public function advisories(): HasMany
    {
        return $this->hasMany(PriceAdvisory::class);
    }
}
