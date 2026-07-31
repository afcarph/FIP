<?php

declare(strict_types=1);

namespace App\Domain\Pricing\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Exogenous signals (Dubai crude, MOPS, USD/PHP) that feed the weekly price
 * forecast. One row per indicator per observation date.
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
