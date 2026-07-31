<?php

declare(strict_types=1);

namespace App\Domain\User\Models;

use App\Domain\Ai\Models\AiChatSession;
use App\Domain\Expense\Models\FuelPurchase;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Models\PriceAlert;
use App\Domain\Pricing\Models\PriceReport;
use App\Domain\Station\Models\GasStation;
use App\Domain\Vehicle\Models\Vehicle;
use App\Support\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Crypt;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * @property int $id
 * @property string $email
 * @property string $status
 * @property int|null $company_id
 */
class User extends Authenticatable implements JWTSubject
{
    use Auditable;
    use HasFactory;
    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    protected $table = 'users';

    protected $guard_name = 'api';

    protected $fillable = [
        'company_id', 'first_name', 'last_name', 'email', 'phone', 'password',
        'avatar_path', 'locale', 'timezone', 'home_city_id', 'status',
        'mfa_enabled', 'biometric_enabled',
    ];

    protected $hidden = [
        'password', 'remember_token', 'mfa_secret', 'mfa_recovery_codes', 'last_login_ip',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'locked_until' => 'datetime',
            'mfa_enabled' => 'boolean',
            'biometric_enabled' => 'boolean',
            'failed_login_attempts' => 'integer',
        ];
    }

    // --------------------------------------------------------------- JWT ---

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    /**
     * Claims embedded in every access token. Keeping role/company here lets
     * edge services authorise without a database round trip; anything
     * security-critical is still re-checked server side.
     */
    public function getJWTCustomClaims(): array
    {
        return [
            'roles' => $this->getRoleNames()->all(),
            'company_id' => $this->company_id,
            'name' => $this->full_name,
            'mfa' => $this->mfa_enabled,
        ];
    }

    // --------------------------------------------------------- Attributes ---

    public function getFullNameAttribute(): string
    {
        return trim("{$this->first_name} {$this->last_name}");
    }

    public function getInitialsAttribute(): string
    {
        return strtoupper(mb_substr($this->first_name, 0, 1).mb_substr($this->last_name, 0, 1));
    }

    // ------------------------------------------------------------ Domain ---

    public function isPlatformAdministrator(): bool
    {
        return $this->hasAnyRole([
            config('fip.roles.super_admin'),
            config('fip.roles.system_admin'),
        ]);
    }

    public function isLocked(): bool
    {
        return $this->locked_until !== null && $this->locked_until->isFuture();
    }

    public function isActive(): bool
    {
        return $this->status === 'active' && ! $this->trashed();
    }

    /** MFA secrets are stored encrypted at rest; never expose the raw column. */
    public function mfaSecret(): ?string
    {
        return $this->mfa_secret === null ? null : Crypt::decryptString($this->mfa_secret);
    }

    public function setMfaSecret(?string $secret): void
    {
        $this->mfa_secret = $secret === null ? null : Crypt::encryptString($secret);
    }

    /** @return list<string> */
    public function recoveryCodes(): array
    {
        return $this->mfa_recovery_codes === null
            ? []
            : (array) json_decode(Crypt::decryptString($this->mfa_recovery_codes), true);
    }

    public function setRecoveryCodes(array $codes): void
    {
        $this->mfa_recovery_codes = Crypt::encryptString(json_encode(array_values($codes), JSON_THROW_ON_ERROR));
    }

    /**
     * Best-known coordinates for radius calculations: the user's home city
     * centroid. Used by alert evaluation when no live GPS fix is available.
     *
     * @return array{lat: float, lng: float}|null
     */
    public function homeCoordinates(): ?array
    {
        $city = $this->relationLoaded('homeCity') ? $this->homeCity : $this->homeCity()->first();

        if ($city?->latitude === null || $city->longitude === null) {
            return null;
        }

        return ['lat' => (float) $city->latitude, 'lng' => (float) $city->longitude];
    }

    /** Reputation weight applied to this user's crowd-sourced submissions. */
    public function trustScore(): float
    {
        $approved = $this->priceReports()->whereIn('status', ['approved', 'auto_approved'])->count();
        $rejected = $this->priceReports()->where('status', 'rejected')->count();
        $total = $approved + $rejected;

        if ($total < 5) {
            return $this->hasVerifiedEmail() ? 0.55 : 0.40;
        }

        // Wilson-style shrinkage keeps a 2-for-2 user from outranking a 90-for-100 one.
        return round(($approved + 2) / ($total + 4), 3);
    }

    // ----------------------------------------------------- Relationships ---

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function homeCity(): BelongsTo
    {
        return $this->belongsTo(\App\Domain\Station\Models\City::class, 'home_city_id');
    }

    public function preferences(): HasOne
    {
        return $this->hasOne(UserPreference::class);
    }

    public function devices(): HasMany
    {
        return $this->hasMany(UserDevice::class);
    }

    public function oauthAccounts(): HasMany
    {
        return $this->hasMany(OauthAccount::class);
    }

    public function driverProfile(): HasOne
    {
        return $this->hasOne(Driver::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class, 'owner_id');
    }

    public function fuelPurchases(): HasMany
    {
        return $this->hasMany(FuelPurchase::class);
    }

    public function priceReports(): HasMany
    {
        return $this->hasMany(PriceReport::class);
    }

    public function priceAlerts(): HasMany
    {
        return $this->hasMany(PriceAlert::class);
    }

    public function managedStations(): HasMany
    {
        return $this->hasMany(GasStation::class, 'managed_by');
    }

    public function chatSessions(): HasMany
    {
        return $this->hasMany(AiChatSession::class);
    }

    public function appNotifications(): HasMany
    {
        return $this->hasMany(Notification::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLog::class);
    }
}
