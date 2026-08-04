<?php

declare(strict_types=1);

namespace App\Domain\Ai\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Registry row for a trained model artefact served by the AI service.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $algorithm
 * @property string $version
 * @property string|null $artefact_path
 * @property array<array-key, mixed>|null $hyperparameters
 * @property array<array-key, mixed>|null $metrics
 * @property Carbon|null $trained_at
 * @property int|null $training_rows
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, PriceForecast> $forecasts
 * @property-read int|null $forecasts_count
 * @property-read Collection<int, AiPrediction> $predictions
 * @property-read int|null $predictions_count
 *
 * @method static Builder<static>|AiModel active()
 * @method static Builder<static>|AiModel newModelQuery()
 * @method static Builder<static>|AiModel newQuery()
 * @method static Builder<static>|AiModel query()
 * @method static Builder<static>|AiModel whereAlgorithm($value)
 * @method static Builder<static>|AiModel whereArtefactPath($value)
 * @method static Builder<static>|AiModel whereCode($value)
 * @method static Builder<static>|AiModel whereCreatedAt($value)
 * @method static Builder<static>|AiModel whereHyperparameters($value)
 * @method static Builder<static>|AiModel whereId($value)
 * @method static Builder<static>|AiModel whereIsActive($value)
 * @method static Builder<static>|AiModel whereMetrics($value)
 * @method static Builder<static>|AiModel whereName($value)
 * @method static Builder<static>|AiModel whereTrainedAt($value)
 * @method static Builder<static>|AiModel whereTrainingRows($value)
 * @method static Builder<static>|AiModel whereUpdatedAt($value)
 * @method static Builder<static>|AiModel whereVersion($value)
 *
 * @mixin \Eloquent
 */
class AiModel extends Model
{
    protected $table = 'ai_models';

    protected $fillable = [
        'code', 'name', 'algorithm', 'version', 'artefact_path',
        'hyperparameters', 'metrics', 'trained_at', 'training_rows', 'is_active',
    ];

    protected function casts(): array
    {
        return [
            'hyperparameters' => 'array',
            'metrics' => 'array',
            'trained_at' => 'datetime',
            'training_rows' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function activeFor(string $code): ?self
    {
        return static::active()->where('code', $code)->latest('trained_at')->first();
    }

    public function metric(string $key): ?float
    {
        return isset($this->metrics[$key]) ? (float) $this->metrics[$key] : null;
    }

    public function forecasts(): HasMany
    {
        return $this->hasMany(PriceForecast::class);
    }

    public function predictions(): HasMany
    {
        return $this->hasMany(AiPrediction::class);
    }
}
