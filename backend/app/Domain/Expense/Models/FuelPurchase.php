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
use Illuminate\Support\Carbon;

/**
 * A single fill-up. Derived metrics (distance, km/L, cost/km) are computed by
 * FuelExpenseService at write time so that reporting queries stay cheap.
 *
 * @property int $id
 * @property int $vehicle_id
 * @property int $user_id
 * @property int|null $driver_id
 * @property int|null $station_id
 * @property int $fuel_type_id
 * @property float $litres
 * @property float $price_per_litre
 * @property float $total_cost
 * @property float|null $odometer
 * @property float|null $distance_since_last
 * @property float|null $km_per_litre
 * @property float|null $cost_per_km
 * @property bool $is_full_tank
 * @property int|null $payment_method_id
 * @property string|null $receipt_path
 * @property string|null $notes
 * @property float|null $latitude
 * @property float|null $longitude
 * @property Carbon $purchased_at
 * @property float|null $anomaly_score
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Driver|null $driver
 * @property-read FuelType $fuelType
 * @property-read PaymentMethod|null $paymentMethod
 * @property-read GasStation|null $station
 * @property-read User|null $user
 * @property-read Vehicle|null $vehicle
 *
 * @method static Builder<static>|FuelPurchase betweenPeriod(\DateTimeInterface $from, \DateTimeInterface $to)
 * @method static \Database\Factories\FuelPurchaseFactory factory($count = null, $state = [])
 * @method static Builder<static>|FuelPurchase newModelQuery()
 * @method static Builder<static>|FuelPurchase newQuery()
 * @method static Builder<static>|FuelPurchase onlyTrashed()
 * @method static Builder<static>|FuelPurchase query()
 * @method static Builder<static>|FuelPurchase suspicious()
 * @method static Builder<static>|FuelPurchase whereAnomalyScore($value)
 * @method static Builder<static>|FuelPurchase whereCostPerKm($value)
 * @method static Builder<static>|FuelPurchase whereCreatedAt($value)
 * @method static Builder<static>|FuelPurchase whereDeletedAt($value)
 * @method static Builder<static>|FuelPurchase whereDistanceSinceLast($value)
 * @method static Builder<static>|FuelPurchase whereDriverId($value)
 * @method static Builder<static>|FuelPurchase whereFuelTypeId($value)
 * @method static Builder<static>|FuelPurchase whereId($value)
 * @method static Builder<static>|FuelPurchase whereIsFullTank($value)
 * @method static Builder<static>|FuelPurchase whereKmPerLitre($value)
 * @method static Builder<static>|FuelPurchase whereLatitude($value)
 * @method static Builder<static>|FuelPurchase whereLitres($value)
 * @method static Builder<static>|FuelPurchase whereLongitude($value)
 * @method static Builder<static>|FuelPurchase whereNotes($value)
 * @method static Builder<static>|FuelPurchase whereOdometer($value)
 * @method static Builder<static>|FuelPurchase wherePaymentMethodId($value)
 * @method static Builder<static>|FuelPurchase wherePricePerLitre($value)
 * @method static Builder<static>|FuelPurchase wherePurchasedAt($value)
 * @method static Builder<static>|FuelPurchase whereReceiptPath($value)
 * @method static Builder<static>|FuelPurchase whereStationId($value)
 * @method static Builder<static>|FuelPurchase whereTotalCost($value)
 * @method static Builder<static>|FuelPurchase whereUpdatedAt($value)
 * @method static Builder<static>|FuelPurchase whereUserId($value)
 * @method static Builder<static>|FuelPurchase whereVehicleId($value)
 * @method static Builder<static>|FuelPurchase withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|FuelPurchase withoutTrashed()
 *
 * @mixin \Eloquent
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
