<?php

declare(strict_types=1);

namespace App\Domain\User\Models;

use App\Domain\Pricing\Models\FuelType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property string $theme
 * @property int|null $preferred_fuel_type_id
 * @property numeric|null $price_alert_threshold
 * @property numeric $alert_radius_km
 * @property bool $notify_price_alerts
 * @property bool $notify_maintenance
 * @property bool $notify_ai_insights
 * @property bool $notify_marketing
 * @property string|null $quiet_hours_start
 * @property string|null $quiet_hours_end
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read FuelType|null $preferredFuelType
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPreference newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPreference newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPreference query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPreference whereAlertRadiusKm($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPreference whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPreference whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPreference whereNotifyAiInsights($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPreference whereNotifyMaintenance($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPreference whereNotifyMarketing($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPreference whereNotifyPriceAlerts($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPreference wherePreferredFuelTypeId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPreference wherePriceAlertThreshold($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPreference whereQuietHoursEnd($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPreference whereQuietHoursStart($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPreference whereTheme($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPreference whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserPreference whereUserId($value)
 *
 * @mixin \Eloquent
 */
class UserPreference extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'theme', 'preferred_fuel_type_id', 'price_alert_threshold',
        'alert_radius_km', 'notify_price_alerts', 'notify_maintenance',
        'notify_ai_insights', 'notify_marketing', 'quiet_hours_start', 'quiet_hours_end',
    ];

    protected function casts(): array
    {
        return [
            'price_alert_threshold' => 'decimal:2',
            'alert_radius_km' => 'decimal:2',
            'notify_price_alerts' => 'boolean',
            'notify_maintenance' => 'boolean',
            'notify_ai_insights' => 'boolean',
            'notify_marketing' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function preferredFuelType(): BelongsTo
    {
        return $this->belongsTo(FuelType::class, 'preferred_fuel_type_id');
    }

    /** Push notifications are suppressed inside the user's quiet hours. */
    public function isWithinQuietHours(?\DateTimeInterface $at = null): bool
    {
        if ($this->quiet_hours_start === null || $this->quiet_hours_end === null) {
            return false;
        }

        $now = ($at ? Carbon::instance($at) : now())->format('H:i:s');
        $start = $this->quiet_hours_start;
        $end = $this->quiet_hours_end;

        return $start <= $end
            ? ($now >= $start && $now <= $end)
            : ($now >= $start || $now <= $end);   // window wraps past midnight
    }
}
