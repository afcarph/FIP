<?php

declare(strict_types=1);

namespace App\Domain\User\Models;

use App\Domain\Ai\Models\AiChatSession;
use App\Domain\Expense\Models\FuelPurchase;
use App\Domain\Fleet\Models\Driver;
use App\Domain\Notification\Models\Notification;
use App\Domain\Notification\Models\PriceAlert;
use App\Domain\Pricing\Models\PriceReport;
use App\Domain\Station\Models\City;
use App\Domain\Station\Models\GasStation;
use App\Domain\Vehicle\Models\Vehicle;
use App\Support\Concerns\Auditable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\DatabaseNotificationCollection;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;
use Tymon\JWTAuth\Contracts\JWTSubject;

/**
 * @property int $id
 * @property int|null $company_id
 * @property string $first_name
 * @property string $last_name
 * @property string $email
 * @property string|null $phone
 * @property string|null $password
 * @property string|null $avatar_path
 * @property string $locale
 * @property string $timezone
 * @property int|null $home_city_id
 * @property string $status
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $phone_verified_at
 * @property bool $mfa_enabled
 * @property string|null $mfa_secret
 * @property string|null $mfa_recovery_codes
 * @property bool $biometric_enabled
 * @property Carbon|null $last_login_at
 * @property string|null $last_login_ip
 * @property int $failed_login_attempts
 * @property Carbon|null $locked_until
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, Notification> $appNotifications
 * @property-read int|null $app_notifications_count
 * @property-read Collection<int, AuditLog> $auditLogs
 * @property-read int|null $audit_logs_count
 * @property-read Collection<int, AiChatSession> $chatSessions
 * @property-read int|null $chat_sessions_count
 * @property-read Company|null $company
 * @property-read Collection<int, UserDevice> $devices
 * @property-read int|null $devices_count
 * @property-read Driver|null $driverProfile
 * @property-read Collection<int, FuelPurchase> $fuelPurchases
 * @property-read int|null $fuel_purchases_count
 * @property-read string $full_name
 * @property-read string $initials
 * @property-read City|null $homeCity
 * @property-read Collection<int, GasStation> $managedStations
 * @property-read int|null $managed_stations_count
 * @property-read DatabaseNotificationCollection<int, DatabaseNotification> $notifications
 * @property-read int|null $notifications_count
 * @property-read Collection<int, OauthAccount> $oauthAccounts
 * @property-read int|null $oauth_accounts_count
 * @property-read Collection<int, Permission> $permissions
 * @property-read int|null $permissions_count
 * @property-read UserPreference|null $preferences
 * @property-read Collection<int, PriceAlert> $priceAlerts
 * @property-read int|null $price_alerts_count
 * @property-read Collection<int, PriceReport> $priceReports
 * @property-read int|null $price_reports_count
 * @property-read Collection<int, Role> $roles
 * @property-read int|null $roles_count
 * @property-read Collection<int, Vehicle> $vehicles
 * @property-read int|null $vehicles_count
 *
 * @method static \Database\Factories\UserFactory factory($count = null, $state = [])
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User permission($permissions, $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User role($roles, $guard = null, $without = false)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereAvatarPath($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereBiometricEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCompanyId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereEmailVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereFailedLoginAttempts($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereFirstName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereHomeCityId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLastLoginAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLastLoginIp($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLastName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLocale($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereLockedUntil($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereMfaEnabled($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereMfaRecoveryCodes($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereMfaSecret($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePassword($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePhone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User wherePhoneVerifiedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereRememberToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereStatus($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereTimezone($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutPermission($permissions)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutRole($roles, $guard = null)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|User withoutTrashed()
 *
 * @mixin \Eloquent
 */
class User extends Authenticatable implements JWTSubject
{
    use Auditable;
    use HasFactory;
    use HasRoles;
    use Notifiable;
    use SoftDeletes;

    protected $table = 'users';

    protected string $guard_name = 'api';

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
        return $this->belongsTo(City::class, 'home_city_id');
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

    /** @return HasMany<FuelPurchase, $this> */
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
