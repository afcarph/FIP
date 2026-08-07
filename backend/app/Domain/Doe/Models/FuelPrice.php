<?php

declare(strict_types=1);

namespace App\Domain\Doe\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One area × product × brand cell from a report.
 *
 * A row with a brand is that brand's published range in that area. A row with
 * `brand` NULL is the area's overall range and common price, which the DOE
 * prints as its own column — stored as published rather than recomputed, so a
 * figure quoted to a user is the one the department stands behind.
 *
 * @property int $id
 * @property int $report_id
 * @property string $area
 * @property string $product
 * @property string|null $fuel_code
 * @property string|null $brand
 * @property float|null $min_price
 * @property float|null $max_price
 * @property float|null $common_price
 */
class FuelPrice extends Model
{
    protected $table = 'fuel_prices';

    protected $guarded = ['id'];

    /** The table has created_at and no updated_at: a published figure is never edited. */
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'min_price' => 'float',
            'max_price' => 'float',
            'common_price' => 'float',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<FuelReport, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(FuelReport::class, 'report_id');
    }

    /**
     * Rows published by a named brand.
     *
     * @param Builder<FuelPrice> $query
     */
    public function scopeBranded(Builder $query): void
    {
        $query->whereNotNull('brand');
    }

    /**
     * The per-area summary rows.
     *
     * @param Builder<FuelPrice> $query
     */
    public function scopeOverall(Builder $query): void
    {
        $query->whereNull('brand');
    }

    /** The midpoint of a published range, for charting a single line. */
    public function getMidPriceAttribute(): ?float
    {
        if ($this->min_price === null || $this->max_price === null) {
            return null;
        }

        return round(($this->min_price + $this->max_price) / 2, 2);
    }
}
