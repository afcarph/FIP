<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Exogenous signals (Dubai crude, MOPS, USD/PHP) that feed the weekly price
 * forecast. One row per indicator per observation date.
 *
 * @property int $id
 * @property string $indicator
 * @property Carbon $observed_on
 * @property float $value
 * @property string $unit
 * @property string $source
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 *
 * @method static Builder<static>|MarketIndicator newModelQuery()
 * @method static Builder<static>|MarketIndicator newQuery()
 * @method static Builder<static>|MarketIndicator query()
 * @method static Builder<static>|MarketIndicator series(string $indicator, int $weeks = 52)
 * @method static Builder<static>|MarketIndicator whereCreatedAt($value)
 * @method static Builder<static>|MarketIndicator whereId($value)
 * @method static Builder<static>|MarketIndicator whereIndicator($value)
 * @method static Builder<static>|MarketIndicator whereObservedOn($value)
 * @method static Builder<static>|MarketIndicator whereSource($value)
 * @method static Builder<static>|MarketIndicator whereUnit($value)
 * @method static Builder<static>|MarketIndicator whereUpdatedAt($value)
 * @method static Builder<static>|MarketIndicator whereValue($value)
 *
 * @mixin \Eloquent
 */
class MarketIndicator extends Model
{
    protected $fillable = ['indicator', 'observed_on', 'value', 'unit', 'source'];

    protected function casts(): array
    {
        return ['observed_on' => 'date', 'value' => 'float'];
    }

    public function scopeSeries(Builder $query, string $indicator, int $weeks = 52): Builder
    {
        return $query->where('indicator', $indicator)
            ->where('observed_on', '>=', now()->subWeeks($weeks))
            ->orderBy('observed_on');
    }

    public static function latest(string $indicator): ?self
    {
        return static::where('indicator', $indicator)->orderByDesc('observed_on')->first();
    }
}
