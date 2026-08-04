<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Station\Models\GasStation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only price archive; the table is range-partitioned by month.
 *
 * @property int $id
 * @property int $station_id
 * @property int $fuel_type_id
 * @property int|null $region_id
 * @property numeric $price
 * @property string $source
 * @property Carbon $recorded_on
 * @property Carbon $recorded_at
 * @property-read FuelType|null $fuelType
 * @property-read GasStation|null $station
 *
 * @method static Builder<static>|FuelPriceHistory between(string $from, string $to)
 * @method static Builder<static>|FuelPriceHistory newModelQuery()
 * @method static Builder<static>|FuelPriceHistory newQuery()
 * @method static Builder<static>|FuelPriceHistory query()
 * @method static Builder<static>|FuelPriceHistory whereFuelTypeId($value)
 * @method static Builder<static>|FuelPriceHistory whereId($value)
 * @method static Builder<static>|FuelPriceHistory wherePrice($value)
 * @method static Builder<static>|FuelPriceHistory whereRecordedAt($value)
 * @method static Builder<static>|FuelPriceHistory whereRecordedOn($value)
 * @method static Builder<static>|FuelPriceHistory whereRegionId($value)
 * @method static Builder<static>|FuelPriceHistory whereSource($value)
 * @method static Builder<static>|FuelPriceHistory whereStationId($value)
 *
 * @mixin \Eloquent
 */
class FuelPriceHistory extends Model
{
    protected $table = 'fuel_price_history';

    public $timestamps = false;

    protected $fillable = ['station_id', 'fuel_type_id', 'region_id', 'price', 'source', 'recorded_on', 'recorded_at'];

    protected function casts(): array
    {
        return ['price' => 'decimal:4', 'recorded_on' => 'date', 'recorded_at' => 'datetime'];
    }

    public function scopeBetween(Builder $query, string $from, string $to): Builder
    {
        return $query->whereBetween('recorded_on', [$from, $to]);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(GasStation::class, 'station_id');
    }

    public function fuelType(): BelongsTo
    {
        return $this->belongsTo(FuelType::class);
    }
}
