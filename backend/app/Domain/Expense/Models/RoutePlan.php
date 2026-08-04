<?php

declare(strict_types=1);

namespace App\Domain\Expense\Models;

use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A stored route optimisation result. `options_payload` holds every candidate
 * returned by the AI service so the user can compare cost / time / fuel.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $vehicle_id
 * @property int|null $trip_id
 * @property string $origin_label
 * @property string $destination_label
 * @property float $origin_lat
 * @property float $origin_lng
 * @property float $destination_lat
 * @property float $destination_lng
 * @property string $optimize_for
 * @property int|null $selected_option
 * @property array<array-key, mixed> $options_payload
 * @property float|null $estimated_savings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Trip|null $trip
 * @property-read User|null $user
 * @property-read Vehicle|null $vehicle
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoutePlan newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoutePlan newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoutePlan query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoutePlan whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoutePlan whereDestinationLabel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoutePlan whereDestinationLat($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoutePlan whereDestinationLng($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoutePlan whereEstimatedSavings($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoutePlan whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoutePlan whereOptimizeFor($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoutePlan whereOptionsPayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoutePlan whereOriginLabel($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoutePlan whereOriginLat($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoutePlan whereOriginLng($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoutePlan whereSelectedOption($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoutePlan whereTripId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoutePlan whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoutePlan whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|RoutePlan whereVehicleId($value)
 *
 * @mixin \Eloquent
 */
class RoutePlan extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'vehicle_id', 'trip_id', 'origin_label', 'destination_label',
        'origin_lat', 'origin_lng', 'destination_lat', 'destination_lng',
        'optimize_for', 'selected_option', 'options_payload', 'estimated_savings',
    ];

    protected function casts(): array
    {
        return [
            'origin_lat' => 'float', 'origin_lng' => 'float',
            'destination_lat' => 'float', 'destination_lng' => 'float',
            'options_payload' => 'array',
            'estimated_savings' => 'float',
            'selected_option' => 'integer',
        ];
    }

    public function cheapestOption(): ?array
    {
        $options = $this->options_payload ?? [];

        if ($options === []) {
            return null;
        }

        usort($options, static fn (array $a, array $b) => ($a['total_cost'] ?? PHP_FLOAT_MAX) <=> ($b['total_cost'] ?? PHP_FLOAT_MAX));

        return $options[0];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function trip(): BelongsTo
    {
        return $this->belongsTo(Trip::class);
    }
}
