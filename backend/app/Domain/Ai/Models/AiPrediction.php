<?php

declare(strict_types=1);

namespace App\Domain\Ai\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Generic prediction envelope for consumption, maintenance and demand models.
 *
 * @property int $id
 * @property int|null $ai_model_id
 * @property string $subject_type
 * @property int $subject_id
 * @property string $prediction_type
 * @property int|null $horizon_days
 * @property array<array-key, mixed> $payload
 * @property float|null $confidence
 * @property Carbon $generated_at
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property-read AiModel|null $model
 * @property-read Model|\Eloquent $subject
 *
 * @method static Builder<static>|AiPrediction fresh()
 * @method static Builder<static>|AiPrediction newModelQuery()
 * @method static Builder<static>|AiPrediction newQuery()
 * @method static Builder<static>|AiPrediction query()
 * @method static Builder<static>|AiPrediction whereAiModelId($value)
 * @method static Builder<static>|AiPrediction whereConfidence($value)
 * @method static Builder<static>|AiPrediction whereCreatedAt($value)
 * @method static Builder<static>|AiPrediction whereExpiresAt($value)
 * @method static Builder<static>|AiPrediction whereGeneratedAt($value)
 * @method static Builder<static>|AiPrediction whereHorizonDays($value)
 * @method static Builder<static>|AiPrediction whereId($value)
 * @method static Builder<static>|AiPrediction wherePayload($value)
 * @method static Builder<static>|AiPrediction wherePredictionType($value)
 * @method static Builder<static>|AiPrediction whereSubjectId($value)
 * @method static Builder<static>|AiPrediction whereSubjectType($value)
 *
 * @mixin \Eloquent
 */
class AiPrediction extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'ai_model_id', 'subject_type', 'subject_id', 'prediction_type',
        'horizon_days', 'payload', 'confidence', 'generated_at', 'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'confidence' => 'float',
            'horizon_days' => 'integer',
            'generated_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    public function scopeFresh(Builder $query): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function subject(): MorphTo
    {
        return $this->morphTo(type: 'subject_type', id: 'subject_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(AiModel::class, 'ai_model_id');
    }
}
