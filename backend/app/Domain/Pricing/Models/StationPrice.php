<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Station\Models\GasStation;
use App\Domain\User\Models\User;
use App\Support\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The live price for one fuel type at one station.
 *
 * Writes are always routed through PriceService so that history, alerts and
 * cache invalidation stay consistent — never update this table directly.
 */
class StationPrice extends Model
{
    use Auditable;
    use HasFactory;

    protected $fillable = [
        'station_id', 'fuel_type_id', 'price', 'previous_price', 'source',
        'confidence', 'effective_at', 'reported_by', 'verified_at',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:4',
            'previous_price' => 'decimal:4',
            'change_amount' => 'decimal:4',
            'confidence' => 'float',
            'effective_at' => 'datetime',
            'verified_at' => 'datetime',
        ];
    }

    public function scopeStale(Builder $query): Builder
    {
        return $query->where('effective_at', '<', now()->subHours((int) config('fip.pricing.stale_after_hours')));
    }

    public function isStale(): bool
    {
        return $this->effective_at->lt(now()->subHours((int) config('fip.pricing.stale_after_hours')));
    }

    public function trend(): string
    {
        $delta = (float) $this->change_amount;

        return match (true) {
            $delta > 0.0001 => 'up',
            $delta < -0.0001 => 'down',
            default => 'flat',
        };
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(GasStation::class, 'station_id');
    }

    public function fuelType(): BelongsTo
    {
        return $this->belongsTo(FuelType::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }
}
