<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Station\Models\GasStation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Append-only price archive; the table is range-partitioned by month. */
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
