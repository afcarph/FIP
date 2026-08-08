<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Models;

use App\Domain\Expense\Models\FuelPurchase;
use App\Domain\Expense\Models\Trip;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Fleet\Models\Fleet;
use App\Domain\Fleet\Models\VehicleFuelReading;
use App\Domain\Maintenance\Models\MaintenanceRecord;
use App\Domain\Maintenance\Models\MaintenanceSchedule;
use App\Domain\Pricing\Models\FuelType;
use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasCompanyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * A vehicle belongs either to a private owner (`owner_id`) or to a company
 * (`company_id`) — the check constraint in the schema keeps at least one set.
 *
 * @property int $id
 * @property int|null $owner_id
 * @property int|null $company_id
 * @property int|null $fleet_id
 * @property int|null $make_id
 * @property int|null $model_id
 * @property int $fuel_type_id
 * @property string|null $nickname
 * @property string $plate_number
 * @property string|null $vin
 * @property string|null $engine_number
 * @property string $vehicle_type
 * @property int|null $year
 * @property string|null $color
 * @property string|null $transmission
 * @property int|null $engine_displacement_cc
 * @property float|null $tank_capacity
 * @property float $current_odometer
 * @property float|null $current_fuel_pct
 * @property float|null $current_fuel_litres
 * @property Carbon|null $fuel_level_at
 * @property float|null $baseline_km_per_litre
 * @property float|null $avg_km_per_litre
 * @property Carbon|null $registration_expiry
 * @property string|null $insurance_provider
 * @property string|null $insurance_policy_no
 * @property Carbon|null $insurance_expiry
 * @property string|null $photo_path
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, VehicleAssignment> $assignments
 * @property-read int|null $assignments_count
 * @property-read Company|null $company
 * @property-read VehicleAssignment|null $currentAssignment
 * @property-read Driver|null $currentDriver
 * @property-read Collection<int, VehicleDocument> $documents
 * @property-read int|null $documents_count
 * @property-read Fleet|null $fleet
 * @property-read Collection<int, FuelPurchase> $fuelPurchases
 * @property-read int|null $fuel_purchases_count
 * @property-read Collection<int, VehicleFuelReading> $fuelReadings
 * @property-read int|null $fuel_readings_count
 * @property-read FuelType $fuelType
 * @property-read string $display_name
 * @property-read Collection<int, MaintenanceRecord> $maintenanceRecords
 * @property-read int|null $maintenance_records_count
 * @property-read Collection<int, MaintenanceSchedule> $maintenanceSchedules
 * @property-read int|null $maintenance_schedules_count
 * @property-read VehicleMake|null $make
 * @property-read VehicleModel|null $model
 * @property-read Collection<int, OdometerReading> $odometerReadings
 * @property-read int|null $odometer_readings_count
 * @property-read User|null $owner
 * @property-read Collection<int, Trip> $trips
 * @property-read int|null $trips_count
 *
 * @method static Builder<static>|Vehicle active()
 * @method static Builder<static>|Vehicle documentsExpiringWithin(int $days)
 * @method static \Database\Factories\VehicleFactory factory($count = null, $state = [])
 * @method static Builder<static>|Vehicle forUser(?\App\Domain\User\Models\User $user)
 * @method static Builder<static>|Vehicle newModelQuery()
 * @method static Builder<static>|Vehicle newQuery()
 * @method static Builder<static>|Vehicle onlyTrashed()
 * @method static Builder<static>|Vehicle query()
 * @method static Builder<static>|Vehicle whereAvgKmPerLitre($value)
 * @method static Builder<static>|Vehicle whereBaselineKmPerLitre($value)
 * @method static Builder<static>|Vehicle whereColor($value)
 * @method static Builder<static>|Vehicle whereCompanyId($value)
 * @method static Builder<static>|Vehicle whereCreatedAt($value)
 * @method static Builder<static>|Vehicle whereCurrentOdometer($value)
 * @method static Builder<static>|Vehicle whereDeletedAt($value)
 * @method static Builder<static>|Vehicle whereEngineDisplacementCc($value)
 * @method static Builder<static>|Vehicle whereEngineNumber($value)
 * @method static Builder<static>|Vehicle whereFleetId($value)
 * @method static Builder<static>|Vehicle whereFuelTypeId($value)
 * @method static Builder<static>|Vehicle whereId($value)
 * @method static Builder<static>|Vehicle whereInsuranceExpiry($value)
 * @method static Builder<static>|Vehicle whereInsurancePolicyNo($value)
 * @method static Builder<static>|Vehicle whereInsuranceProvider($value)
 * @method static Builder<static>|Vehicle whereMakeId($value)
 * @method static Builder<static>|Vehicle whereModelId($value)
 * @method static Builder<static>|Vehicle whereNickname($value)
 * @method static Builder<static>|Vehicle whereOwnerId($value)
 * @method static Builder<static>|Vehicle wherePhotoPath($value)
 * @method static Builder<static>|Vehicle wherePlateNumber($value)
 * @method static Builder<static>|Vehicle whereRegistrationExpiry($value)
 * @method static Builder<static>|Vehicle whereStatus($value)
 * @method static Builder<static>|Vehicle whereTankCapacity($value)
 * @method static Builder<static>|Vehicle whereTransmission($value)
 * @method static Builder<static>|Vehicle whereUpdatedAt($value)
 * @method static Builder<static>|Vehicle whereVehicleType($value)
 * @method static Builder<static>|Vehicle whereVin($value)
 * @method static Builder<static>|Vehicle whereYear($value)
 * @method static Builder<static>|Vehicle withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Vehicle withoutTrashed()
 *
 * @mixin \Eloquent
 */
class Vehicle extends Model
{
    use Auditable;
    use HasCompanyScope;
    use HasFactory;
    use SoftDeletes;

    /**
     * `display_name` falls back to "<make> <model>", so every caller that reads
     * it needs both relations. They were easy to forget — the dashboard loaded
     * only `fuelType` and threw under preventLazyLoading, and would have run an
     * N+1 in production instead. Both are small reference tables, so eager
     * loading them costs two queries per request and makes the accessor
     * self-sufficient wherever it is used.
     */
    protected $with = ['make', 'model'];

    protected $fillable = [
        'owner_id', 'company_id', 'fleet_id', 'make_id', 'model_id', 'fuel_type_id',
        'nickname', 'plate_number', 'vin', 'engine_number', 'vehicle_type', 'year',
        'color', 'transmission', 'engine_displacement_cc', 'tank_capacity',
        'current_odometer', 'baseline_km_per_litre', 'avg_km_per_litre',
        'registration_expiry', 'insurance_provider', 'insurance_policy_no',
        'insurance_expiry', 'photo_path', 'status',
    ];

    /**
     * `current_fuel_pct`, `current_fuel_litres` and `fuel_level_at` are
     * deliberately absent from $fillable. They are a cache of the newest row in
     * `vehicle_fuel_readings` and belong to FuelLevelService; letting a vehicle
     * update set them would allow the dashboard to disagree with the history it
     * is supposed to summarise.
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'engine_displacement_cc' => 'integer',
            'tank_capacity' => 'float',
            'current_odometer' => 'float',
            'baseline_km_per_litre' => 'float',
            'avg_km_per_litre' => 'float',
            'current_fuel_pct' => 'float',
            'current_fuel_litres' => 'float',
            'fuel_level_at' => 'datetime',
            'registration_expiry' => 'date',
            'insurance_expiry' => 'date',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $vehicle): void {
            $vehicle->plate_number = strtoupper(trim($vehicle->plate_number));
        });
    }

    protected function ownerColumn(): ?string
    {
        return 'vehicles.owner_id';
    }

    // ------------------------------------------------------------ Scopes ---

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeDocumentsExpiringWithin(Builder $query, int $days): Builder
    {
        $limit = now()->addDays($days)->toDateString();

        return $query->where(fn (Builder $q) => $q
            ->whereBetween('registration_expiry', [now()->toDateString(), $limit])
            ->orWhereBetween('insurance_expiry', [now()->toDateString(), $limit]));
    }

    // ------------------------------------------------------------ Domain ---

    public function getDisplayNameAttribute(): string
    {
        return $this->nickname ?: trim(($this->make?->name ?? '').' '.($this->model?->name ?? '')) ?: $this->plate_number;
    }

    /**
     * Rolling efficiency over the most recent full-tank fill-ups. Partial
     * fills are excluded because tank-to-tank distance is only meaningful
     * between two brim-full points.
     */
    public function recalculateEfficiency(int $sampleSize = 10): ?float
    {
        $average = $this->fuelPurchases()
            ->where('is_full_tank', true)
            ->whereNotNull('km_per_litre')
            ->latest('purchased_at')
            ->limit($sampleSize)
            ->avg('km_per_litre');

        if ($average === null) {
            return null;
        }

        $this->forceFill(['avg_km_per_litre' => round((float) $average, 2)])->saveQuietly();

        return (float) $this->avg_km_per_litre;
    }

    /** Percentage deviation from this vehicle's own efficiency baseline. */
    public function efficiencyDeviationPct(): ?float
    {
        if (! $this->baseline_km_per_litre || ! $this->avg_km_per_litre) {
            return null;
        }

        return round((($this->avg_km_per_litre - $this->baseline_km_per_litre) / $this->baseline_km_per_litre) * 100, 2);
    }

    public function estimatedRangeKm(?float $litresInTank = null): ?float
    {
        $kpl = $this->avg_km_per_litre ?? $this->baseline_km_per_litre;
        $litres = $litresInTank ?? $this->tank_capacity;

        return ($kpl && $litres) ? round($kpl * $litres, 1) : null;
    }

    public function estimatedFullTankCost(float $pricePerLitre): ?float
    {
        return $this->tank_capacity ? round($this->tank_capacity * $pricePerLitre, 2) : null;
    }

    // ----------------------------------------------------- Relationships ---

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fleet(): BelongsTo
    {
        return $this->belongsTo(Fleet::class);
    }

    public function make(): BelongsTo
    {
        return $this->belongsTo(VehicleMake::class, 'make_id');
    }

    public function model(): BelongsTo
    {
        return $this->belongsTo(VehicleModel::class, 'model_id');
    }

    public function fuelType(): BelongsTo
    {
        return $this->belongsTo(FuelType::class);
    }

    /** @return HasMany<FuelPurchase, $this> */
    public function fuelPurchases(): HasMany
    {
        return $this->hasMany(FuelPurchase::class);
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function odometerReadings(): HasMany
    {
        return $this->hasMany(OdometerReading::class);
    }

    /** @return HasMany<VehicleFuelReading, $this> */
    public function fuelReadings(): HasMany
    {
        return $this->hasMany(VehicleFuelReading::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VehicleDocument::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(VehicleAssignment::class);
    }

    public function currentAssignment(): HasOne
    {
        return $this->hasOne(VehicleAssignment::class)->whereNull('released_at')->latestOfMany('assigned_at');
    }

    public function currentDriver(): BelongsTo
    {
        return $this->belongsTo(Driver::class, 'id', 'id')
            ->whereIn('id', fn ($q) => $q->select('driver_id')
                ->from('vehicle_assignments')
                ->where('vehicle_id', $this->getKey())
                ->whereNull('released_at'));
    }

    /** @return HasMany<MaintenanceRecord, $this> */
    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class);
    }

    /** @return HasMany<MaintenanceSchedule, $this> */
    public function maintenanceSchedules(): HasMany
    {
        return $this->hasMany(MaintenanceSchedule::class);
    }
}
