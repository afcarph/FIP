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
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

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
