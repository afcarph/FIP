<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Models;

use App\Domain\Expense\Models\FuelPurchase;
use App\Domain\Expense\Models\Trip;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Fleet\Models\Fleet;
use App\Domain\Maintenance\Models\MaintenanceRecord;
use App\Domain\Maintenance\Models\MaintenanceSchedule;
use App\Domain\Pricing\Models\FuelType;
use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use App\Support\Concerns\Auditable;
use App\Support\Concerns\HasCompanyScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A vehicle belongs either to a private owner (`owner_id`) or to a company
 * (`company_id`) — the check constraint in the schema keeps at least one set.
 *
 * @property float $current_odometer
 * @property float|null $avg_km_per_litre
 */
class Vehicle extends Model
{
    use Auditable;
    use HasCompanyScope;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'owner_id', 'company_id', 'fleet_id', 'make_id', 'model_id', 'fuel_type_id',
        'nickname', 'plate_number', 'vin', 'engine_number', 'vehicle_type', 'year',
        'color', 'transmission', 'engine_displacement_cc', 'tank_capacity',
        'current_odometer', 'baseline_km_per_litre', 'avg_km_per_litre',
        'registration_expiry', 'insurance_provider', 'insurance_policy_no',
        'insurance_expiry', 'photo_path', 'status',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'engine_displacement_cc' => 'integer',
            'tank_capacity' => 'float',
            'current_odometer' => 'float',
            'baseline_km_per_litre' => 'float',
            'avg_km_per_litre' => 'float',
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

    protected function ownerColumn(): string
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

    public function maintenanceRecords(): HasMany
    {
        return $this->hasMany(MaintenanceRecord::class);
    }

    public function maintenanceSchedules(): HasMany
    {
        return $this->hasMany(MaintenanceSchedule::class);
    }
}
