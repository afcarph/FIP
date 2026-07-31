<?php

declare(strict_types=1);

namespace App\Domain\Station\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
