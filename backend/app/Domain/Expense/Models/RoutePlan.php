<?php

declare(strict_types=1);

namespace App\Domain\Expense\Models;

use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stored route optimisation result. `options_payload` holds every candidate
 * returned by the AI service so the user can compare cost / time / fuel.
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
