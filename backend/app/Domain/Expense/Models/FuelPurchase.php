<?php

declare(strict_types=1);

namespace App\Domain\Expense\Models;

use App\Domain\Fleet\Models\Driver;
use App\Domain\Pricing\Models\FuelType;
use App\Domain\Station\Models\GasStation;
use App\Domain\Station\Models\PaymentMethod;
use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;
use App\Support\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single fill-up. Derived metrics (distance, km/L, cost/km) are computed by
 * FuelExpenseService at write time so that reporting queries stay cheap.
 */
class FuelPurchase extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'vehicle_id', 'user_id', 'driver_id', 'station_id', 'fuel_type_id', 'litres',
        'price_per_litre', 'total_cost', 'odometer', 'distance_since_last',
        'km_per_litre', 'cost_per_km', 'is_full_tank', 'payment_method_id',
        'receipt_path', 'notes', 'latitude', 'longitude', 'purchased_at', 'anomaly_score',
    ];

    protected function casts(): array
    {
        return [
            'litres' => 'float',
            'price_per_litre' => 'float',
            'total_cost' => 'float',
            'odometer' => 'float',
            'distance_since_last' => 'float',
            'km_per_litre' => 'float',
            'cost_per_km' => 'float',
            'is_full_tank' => 'boolean',
            'latitude' => 'float',
            'longitude' => 'float',
            'purchased_at' => 'datetime',
            'anomaly_score' => 'float',
        ];
    }

    public function scopeBetweenPeriod(Builder $query, \DateTimeInterface $from, \DateTimeInterface $to): Builder
    {
        return $query->whereBetween('purchased_at', [$from, $to]);
    }

    public function scopeSuspicious(Builder $query): Builder
    {
        return $query->where('anomaly_score', '>=', (float) config('fip.fraud.score_threshold'));
    }

    /** Drivers may only correct a recent entry; older rows need a manager. */
    public function isEditableBy(User $user): bool
    {
        if ($user->isPlatformAdministrator() || $user->hasRole(config('fip.roles.fleet_manager'))) {
            return true;
        }

        return $this->user_id === $user->getKey()
            && $this->purchased_at->gt(now()->subHours((int) config('fip.expenses.edit_window_hours')));
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(GasStation::class, 'station_id');
    }

    public function fuelType(): BelongsTo
    {
        return $this->belongsTo(FuelType::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }
}
