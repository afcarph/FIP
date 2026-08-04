<?php

declare(strict_types=1);

namespace App\Domain\Ai\Models;

use App\Domain\Pricing\Models\FuelType;
use App\Domain\Station\Models\Region;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A weekly pump-price prediction. Once the DOE publishes the real adjustment
 * the row is back-filled with `actual_change` and `absolute_error`, which is
 * what drives the published accuracy figure.
 *
 * @property int $id
 * @property int|null $ai_model_id
 * @property int $fuel_type_id
 * @property int|null $region_id
 * @property Carbon $forecast_for
 * @property Carbon $generated_at
 * @property string $direction
 * @property float $change_amount
 * @property float|null $predicted_price
 * @property float|null $lower_bound
 * @property float|null $upper_bound
 * @property float $confidence
 * @property array<array-key, mixed>|null $drivers
 * @property string|null $narrative
 * @property float|null $actual_change
 * @property float|null $absolute_error
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read FuelType $fuelType
 * @property-read AiModel|null $model
 * @property-read Region|null $region
 *
 * @method static Builder<static>|PriceForecast latestGeneration()
 * @method static Builder<static>|PriceForecast newModelQuery()
 * @method static Builder<static>|PriceForecast newQuery()
 * @method static Builder<static>|PriceForecast query()
 * @method static Builder<static>|PriceForecast upcoming()
 * @method static Builder<static>|PriceForecast whereAbsoluteError($value)
 * @method static Builder<static>|PriceForecast whereActualChange($value)
 * @method static Builder<static>|PriceForecast whereAiModelId($value)
 * @method static Builder<static>|PriceForecast whereChangeAmount($value)
 * @method static Builder<static>|PriceForecast whereConfidence($value)
 * @method static Builder<static>|PriceForecast whereCreatedAt($value)
 * @method static Builder<static>|PriceForecast whereDirection($value)
 * @method static Builder<static>|PriceForecast whereDrivers($value)
 * @method static Builder<static>|PriceForecast whereForecastFor($value)
 * @method static Builder<static>|PriceForecast whereFuelTypeId($value)
 * @method static Builder<static>|PriceForecast whereGeneratedAt($value)
 * @method static Builder<static>|PriceForecast whereId($value)
 * @method static Builder<static>|PriceForecast whereLowerBound($value)
 * @method static Builder<static>|PriceForecast whereNarrative($value)
 * @method static Builder<static>|PriceForecast wherePredictedPrice($value)
 * @method static Builder<static>|PriceForecast whereRegionId($value)
 * @method static Builder<static>|PriceForecast whereUpdatedAt($value)
 * @method static Builder<static>|PriceForecast whereUpperBound($value)
 *
 * @mixin \Eloquent
 */
class PriceForecast extends Model
{
    use HasFactory;

    protected $fillable = [
        'ai_model_id', 'fuel_type_id', 'region_id', 'forecast_for', 'generated_at',
        'direction', 'change_amount', 'predicted_price', 'lower_bound', 'upper_bound',
        'confidence', 'drivers', 'narrative', 'actual_change', 'absolute_error',
    ];

    protected function casts(): array
    {
        return [
            'forecast_for' => 'date',
            'generated_at' => 'datetime',
            'change_amount' => 'float',
            'predicted_price' => 'float',
            'lower_bound' => 'float',
            'upper_bound' => 'float',
            'confidence' => 'float',
            'drivers' => 'array',
            'actual_change' => 'float',
            'absolute_error' => 'float',
        ];
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('forecast_for', '>=', now()->startOfWeek())->orderBy('forecast_for');
    }

    public function scopeLatestGeneration(Builder $query): Builder
    {
        return $query->whereIn('generated_at', fn ($q) => $q
            ->selectRaw('MAX(generated_at)')
            ->from('price_forecasts')
            ->groupBy('fuel_type_id', 'region_id', 'forecast_for'));
    }

    public function isConfident(): bool
    {
        return $this->confidence >= (float) config('fip.forecast.min_confidence');
    }

    /** Human-friendly label for the UI badge. */
    public function label(): string
    {
        return match ($this->direction) {
            'increase' => sprintf('₱%.2f/L increase expected', abs($this->change_amount)),
            'rollback' => sprintf('₱%.2f/L rollback expected', abs($this->change_amount)),
            default => 'No change expected',
        };
    }

    /** Ranked contributing factors for the "why" panel. */
    public function topDrivers(int $limit = 3): array
    {
        $drivers = $this->drivers ?? [];
        usort($drivers, static fn (array $a, array $b) => ($b['weight'] ?? 0) <=> ($a['weight'] ?? 0));

        return array_slice($drivers, 0, $limit);
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
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
