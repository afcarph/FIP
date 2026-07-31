<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Station\Models\Region;
use App\Support\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A weekly DOE pump-price adjustment ("increase" / "rollback"). */
class PriceAdvisory extends Model
{
    use Auditable;

    protected $fillable = [
        'fuel_type_id', 'region_id', 'week_start', 'effective_at',
        'change_amount', 'direction', 'source', 'source_url', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'week_start' => 'date',
            'effective_at' => 'datetime',
            'change_amount' => 'decimal:4',
        ];
    }

    public function scopeNationwide(Builder $query): Builder
    {
        return $query->whereNull('region_id');
    }

    public function scopeRecent(Builder $query, int $weeks = 12): Builder
    {
        return $query->where('week_start', '>=', now()->subWeeks($weeks)->startOfWeek())
            ->orderByDesc('week_start');
    }

    public function fuelType(): BelongsTo
    {
        return $this->belongsTo(FuelType::class);
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }
}
