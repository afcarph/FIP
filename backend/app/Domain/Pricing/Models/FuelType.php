<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
