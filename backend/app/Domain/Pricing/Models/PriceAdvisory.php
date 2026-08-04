<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use App\Domain\Station\Models\Region;
use App\Support\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A weekly DOE pump-price adjustment ("increase" / "rollback").
 *
 * @property int $id
 * @property int $fuel_type_id
 * @property int|null $region_id
 * @property Carbon $week_start
 * @property Carbon $effective_at
 * @property numeric $change_amount
 * @property string $direction
 * @property string $source
 * @property string|null $source_url
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read FuelType $fuelType
 * @property-read Region|null $region
 *
 * @method static Builder<static>|PriceAdvisory nationwide()
 * @method static Builder<static>|PriceAdvisory newModelQuery()
 * @method static Builder<static>|PriceAdvisory newQuery()
 * @method static Builder<static>|PriceAdvisory query()
 * @method static Builder<static>|PriceAdvisory recent(int $weeks = 12)
 * @method static Builder<static>|PriceAdvisory whereChangeAmount($value)
 * @method static Builder<static>|PriceAdvisory whereCreatedAt($value)
 * @method static Builder<static>|PriceAdvisory whereDirection($value)
 * @method static Builder<static>|PriceAdvisory whereEffectiveAt($value)
 * @method static Builder<static>|PriceAdvisory whereFuelTypeId($value)
 * @method static Builder<static>|PriceAdvisory whereId($value)
 * @method static Builder<static>|PriceAdvisory whereNotes($value)
 * @method static Builder<static>|PriceAdvisory whereRegionId($value)
 * @method static Builder<static>|PriceAdvisory whereSource($value)
 * @method static Builder<static>|PriceAdvisory whereSourceUrl($value)
 * @method static Builder<static>|PriceAdvisory whereUpdatedAt($value)
 * @method static Builder<static>|PriceAdvisory whereWeekStart($value)
 *
 * @mixin \Eloquent
 */
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
