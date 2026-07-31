<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Domain\Pricing\Models\FuelType;
use App\Domain\Station\Models\City;
use App\Domain\Station\Models\GasStation;
use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** A standing user rule: "tell me when diesel drops below ₱57.50 near me". */
class PriceAlert extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'fuel_type_id', 'station_id', 'city_id', 'condition',
        'threshold', 'radius_km', 'is_active', 'last_fired_at',
    ];

    protected function casts(): array
    {
        return [
            'threshold' => 'float',
            'radius_km' => 'float',
            'is_active' => 'boolean',
            'last_fired_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function matches(float $price): bool
    {
        return match ($this->condition) {
            'below' => $price <= $this->threshold,
            'above' => $price >= $this->threshold,
            default => true,
        };
    }

    /** Avoid re-notifying about the same movement within the cooldown window. */
    public function isCoolingDown(int $hours = 6): bool
    {
        return $this->last_fired_at !== null && $this->last_fired_at->gt(now()->subHours($hours));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function fuelType(): BelongsTo
    {
        return $this->belongsTo(FuelType::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(GasStation::class, 'station_id');
    }

    public function city(): BelongsTo
    {
        return $this->belongsTo(City::class);
    }
}
