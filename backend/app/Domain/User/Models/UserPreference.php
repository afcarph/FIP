<?php

declare(strict_types=1);

namespace App\Domain\User\Models;

use App\Domain\Pricing\Models\FuelType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

        $now = ($at ? \Carbon\Carbon::instance($at) : now())->format('H:i:s');
        $start = $this->quiet_hours_start;
        $end = $this->quiet_hours_end;

        return $start <= $end
            ? ($now >= $start && $now <= $end)
            : ($now >= $start || $now <= $end);   // window wraps past midnight
    }
}
