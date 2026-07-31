<?php

declare(strict_types=1);

namespace App\Domain\Ai\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Registry row for a trained model artefact served by the AI service. */
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
