<?php

declare(strict_types=1);

namespace App\Domain\Fleet\Models;

use App\Domain\Expense\Models\FuelPurchase;
use App\Domain\Expense\Models\Trip;
use App\Domain\User\Models\Company;
use App\Domain\User\Models\User;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Models\VehicleAssignment;
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
 * @property int $id
 * @property int|null $user_id
 * @property int|null $company_id
 * @property int|null $fleet_id
 * @property string|null $employee_no
 * @property string $first_name
 * @property string $last_name
 * @property string|null $phone
 * @property string|null $licence_number
 * @property string|null $licence_type
 * @property Carbon|null $licence_expiry
 * @property Carbon|null $hired_at
 * @property float $safety_score
 * @property float $efficiency_score
 * @property string $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, VehicleAssignment> $assignments
 * @property-read int|null $assignments_count
 * @property-read Company|null $company
 * @property-read VehicleAssignment|null $currentAssignment
 * @property-read Fleet|null $fleet
 * @property-read Collection<int, FuelPurchase> $fuelPurchases
 * @property-read int|null $fuel_purchases_count
 * @property-read string $full_name
 * @property-read Collection<int, Trip> $trips
 * @property-read int|null $trips_count
 * @property-read User|null $user
 *
 * @method static Builder<static>|Driver active()
 * @method static Builder<static>|Driver forUser(?\App\Domain\User\Models\User $user)
 * @method static Builder<static>|Driver licenceExpiringWithin(int $days)
 * @method static Builder<static>|Driver newModelQuery()
 * @method static Builder<static>|Driver newQuery()
 * @method static Builder<static>|Driver onlyTrashed()
 * @method static Builder<static>|Driver query()
 * @method static Builder<static>|Driver whereCompanyId($value)
 * @method static Builder<static>|Driver whereCreatedAt($value)
 * @method static Builder<static>|Driver whereDeletedAt($value)
 * @method static Builder<static>|Driver whereEfficiencyScore($value)
 * @method static Builder<static>|Driver whereEmployeeNo($value)
 * @method static Builder<static>|Driver whereFirstName($value)
 * @method static Builder<static>|Driver whereFleetId($value)
 * @method static Builder<static>|Driver whereHiredAt($value)
 * @method static Builder<static>|Driver whereId($value)
 * @method static Builder<static>|Driver whereLastName($value)
 * @method static Builder<static>|Driver whereLicenceExpiry($value)
 * @method static Builder<static>|Driver whereLicenceNumber($value)
 * @method static Builder<static>|Driver whereLicenceType($value)
 * @method static Builder<static>|Driver wherePhone($value)
 * @method static Builder<static>|Driver whereSafetyScore($value)
 * @method static Builder<static>|Driver whereStatus($value)
 * @method static Builder<static>|Driver whereUpdatedAt($value)
 * @method static Builder<static>|Driver whereUserId($value)
 * @method static Builder<static>|Driver withTrashed(bool $withTrashed = true)
 * @method static Builder<static>|Driver withoutTrashed()
 *
 * @mixin \Eloquent
 */
class Driver extends Model
{
    use Auditable;
    use HasCompanyScope;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'user_id', 'company_id', 'fleet_id', 'employee_no', 'first_name', 'last_name',
        'phone', 'licence_number', 'licence_type', 'licence_expiry', 'hired_at',
        'safety_score', 'efficiency_score', 'status',
    ];

    protected function casts(): array
    {
        return [
            'licence_expiry' => 'date',
            'hired_at' => 'date',
            'safety_score' => 'float',
            'efficiency_score' => 'float',
        ];
    }

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    protected function ownerColumn(): ?string
    {
        return 'drivers.user_id';
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeLicenceExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->whereBetween('licence_expiry', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function fleet(): BelongsTo
    {
        return $this->belongsTo(Fleet::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(VehicleAssignment::class);
    }

    public function currentAssignment(): HasOne
    {
        return $this->hasOne(VehicleAssignment::class)->whereNull('released_at')->latestOfMany('assigned_at');
    }

    public function currentVehicle(): ?Vehicle
    {
        return $this->currentAssignment?->vehicle;
    }

    public function trips(): HasMany
    {
        return $this->hasMany(Trip::class);
    }

    public function fuelPurchases(): HasMany
    {
        return $this->hasMany(FuelPurchase::class);
    }
}
