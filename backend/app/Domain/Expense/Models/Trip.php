<?php

declare(strict_types=1);

namespace App\Domain\Expense\Models;

use App\Domain\Fleet\Models\Driver;
use App\Domain\Fleet\Models\Fleet;
use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasCompanyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $company_id
 * @property int|null $created_by
 * @property string|null $purpose
 * @property Carbon|null $scheduled_for
 * @property string|null $notes
 * @property int|null $odometer_start
 * @property int|null $odometer_end
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $cancelled_at
 * @property string|null $cancellation_reason
 * @property-read Company|null $company
 * @property-read User|null $creator
 * @property int $vehicle_id
 * @property int|null $driver_id
 * @property int|null $fleet_id
 * @property string|null $reference_no
 * @property string|null $origin_label
 * @property float|null $origin_lat
 * @property float|null $origin_lng
 * @property string|null $destination_label
 * @property float|null $destination_lat
 * @property float|null $destination_lng
 * @property float|null $distance_km
 * @property int|null $duration_minutes
 * @property float|null $fuel_consumed_l
 * @property float|null $fuel_cost
 * @property float|null $toll_cost
 * @property Carbon|null $started_at
 * @property Carbon|null $ended_at
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Driver|null $driver
 * @property-read Fleet|null $fleet
 * @property-read RoutePlan|null $routePlan
 * @property-read Vehicle|null $vehicle
 *
 * @method static Builder<static>|Trip completed()
 * @method static Builder<static>|Trip newModelQuery()
 * @method static Builder<static>|Trip newQuery()
 * @method static Builder<static>|Trip onlyTrashed()
 * @method static Builder<static>|Trip query()
 * @method static Builder<static>|Trip whereCreatedAt($value)
 * @method static Builder<static>|Trip whereDeletedAt($value)
 * @method static Builder<static>|Trip whereDestinationLabel($value)
 * @method static Builder<static>|Trip whereDestinationLat($value)
 * @method static Builder<static>|Trip whereDestinationLng($value)
 * @method static Builder<static>|Trip whereDistanceKm($value)
 * @method static Builder<static>|Trip whereDriverId($value)
 * @method static Builder<static>|Trip whereDurationMinutes($value)
 * @method static Builder<static>|Trip whereEndedAt($value)
 * @method static Builder<static>|Trip whereFleetId($value)
 * @method static Builder<static>|Trip whereFuelConsumedL($value)
 * @method static Builder<static>|Trip whereFuelCost($value)
 * @method static Builder<static>|Trip whereId($value)
 * @method static Builder<static>|Trip whereOriginLabel($value)
 * @method static Builder<static>|Trip whereOriginLat($value)
 * @method static Builder<static>|Trip whereOriginLng($value)
 * @method static Builder<static>|Trip whereReferenceNo($value)
 * @method static Builder<static>|Trip whereStartedAt($value)
 * @method static Builder<static>|Trip whereStatus($value)
 * @method static Builder<static>|Trip whereTollCost($value)
 * @method static Builder<static>|Trip whereUpdatedAt($value)
 * @method static Builder<static>|Trip whereVehicleId($value)
 * @method static Builder<static>|Trip withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Trip withoutTrashed()
 *
 * @mixin \Eloquent
 */
class Trip extends Model
{
    use Auditable;
    use HasCompanyScope;
    use HasFactory;
    use SoftDeletes;

    /*
     * The dispatch lifecycle.
     *
     * DRAFT -> DISPATCHED -> IN_PROGRESS -> COMPLETED, with CANCELLED reachable
     * only before the wheels turn. Cancelling an in-progress trip is deliberately
     * not a transition: something physically happened, and the record of it
     * should be completed rather than erased. A trip that went wrong is closed
     * with notes, which keeps the odometer and timing honest.
     *
     * That restriction is also what keeps the fleet dashboard correct. It counts
     * a vehicle as on trip when a row has started and not ended, so a cancelled
     * trip must never hold a started_at — and because cancellation cannot be
     * reached from IN_PROGRESS, it cannot.
     */
    public const STATUS_DRAFT = 'draft';

    public const STATUS_DISPATCHED = 'dispatched';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    /** @var array<string, list<string>> */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_DISPATCHED, self::STATUS_CANCELLED],
        self::STATUS_DISPATCHED => [self::STATUS_IN_PROGRESS, self::STATUS_CANCELLED],
        self::STATUS_IN_PROGRESS => [self::STATUS_COMPLETED],
        self::STATUS_COMPLETED => [],
        self::STATUS_CANCELLED => [],
    ];

    protected $fillable = [
        'company_id', 'vehicle_id', 'driver_id', 'fleet_id', 'created_by',
        'reference_no', 'origin_label',
        'origin_lat', 'origin_lng', 'destination_label', 'destination_lat',
        'destination_lng', 'purpose', 'scheduled_for', 'notes',
        'odometer_start', 'odometer_end',
        'distance_km', 'duration_minutes', 'fuel_consumed_l',
        'fuel_cost', 'toll_cost', 'started_at', 'dispatched_at', 'ended_at',
        'cancelled_at', 'cancellation_reason', 'status',
    ];

    protected function casts(): array
    {
        return [
            'origin_lat' => 'float', 'origin_lng' => 'float',
            'destination_lat' => 'float', 'destination_lng' => 'float',
            'distance_km' => 'float', 'duration_minutes' => 'integer',
            'fuel_consumed_l' => 'float', 'fuel_cost' => 'float', 'toll_cost' => 'float',
            'started_at' => 'datetime', 'ended_at' => 'datetime',
            'dispatched_at' => 'datetime', 'cancelled_at' => 'datetime',
            'scheduled_for' => 'datetime',
            'odometer_start' => 'integer', 'odometer_end' => 'integer',
        ];
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Trips that still occupy their vehicle and driver.
     *
     * Everything before a terminal state. A draft holds the pair too — it was
     * planned for them — which is what stops two drafts being written against
     * one vehicle for the same job.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::STATUS_COMPLETED, self::STATUS_CANCELLED]);
    }

    public function canTransitionTo(string $status): bool
    {
        return in_array($status, self::TRANSITIONS[$this->status] ?? [], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function totalCost(): float
    {
        return round((float) $this->fuel_cost + (float) $this->toll_cost, 2);
    }

    public function costPerKm(): ?float
    {
        return $this->distance_km ? round($this->totalCost() / $this->distance_km, 4) : null;
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    public function fleet(): BelongsTo
    {
        return $this->belongsTo(Fleet::class);
    }

    public function routePlan(): HasOne
    {
        return $this->hasOne(RoutePlan::class);
    }
}
