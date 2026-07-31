<?php

declare(strict_types=1);

namespace App\Domain\Maintenance\Models;

use App\Domain\Vehicle\Models\Vehicle;
use App\Support\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The next occurrence of one service item for one vehicle.
 *
 * Two independent clocks apply — calendar days and odometer kilometres —
 * and whichever falls first wins. `predicted_due_at` is the AI estimate,
 * which usually lands earlier than the fixed interval for hard-worked units.
 */
class MaintenanceSchedule extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_DUE_SOON = 'due_soon';
    public const STATUS_OVERDUE = 'overdue';
    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'vehicle_id', 'maintenance_type_id', 'interval_km', 'interval_days',
        'last_performed_at', 'last_odometer', 'due_at', 'due_odometer',
        'predicted_due_at', 'prediction_confidence', 'status',
    ];

    protected function casts(): array
    {
        return [
            'last_performed_at' => 'date',
            'due_at' => 'date',
            'predicted_due_at' => 'date',
            'last_odometer' => 'float',
            'due_odometer' => 'float',
            'prediction_confidence' => 'float',
            'interval_km' => 'integer',
            'interval_days' => 'integer',
        ];
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_DUE_SOON, self::STATUS_OVERDUE]);
    }

    public function scopeDueWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('due_at')
            ->where('due_at', '<=', now()->addDays($days)->toDateString())
            ->whereNot('status', self::STATUS_COMPLETED);
    }

    /** Recompute due dates/odometers and the derived status. */
    public function recalculate(?float $currentOdometer = null): self
    {
        if ($this->interval_days && $this->last_performed_at) {
            $this->due_at = $this->last_performed_at->copy()->addDays($this->interval_days);
        }

        if ($this->interval_km && $this->last_odometer !== null) {
            $this->due_odometer = $this->last_odometer + $this->interval_km;
        }

        $this->status = $this->deriveStatus($currentOdometer ?? $this->vehicle?->current_odometer);
        $this->save();

        return $this;
    }

    private function deriveStatus(?float $odometer): string
    {
        $dueSoonDays = (int) config('fip.maintenance.due_soon_days');
        $dueSoonKm = (int) config('fip.maintenance.due_soon_km');

        $overdue = ($this->due_at !== null && $this->due_at->isPast())
            || ($this->due_odometer !== null && $odometer !== null && $odometer >= $this->due_odometer);

        if ($overdue) {
            return self::STATUS_OVERDUE;
        }

        $dueSoon = ($this->due_at !== null && $this->due_at->lte(now()->addDays($dueSoonDays)))
            || ($this->due_odometer !== null && $odometer !== null && ($this->due_odometer - $odometer) <= $dueSoonKm);

        return $dueSoon ? self::STATUS_DUE_SOON : self::STATUS_SCHEDULED;
    }

    public function kilometresRemaining(?float $odometer = null): ?float
    {
        $odometer ??= $this->vehicle?->current_odometer;

        return ($this->due_odometer !== null && $odometer !== null)
            ? round($this->due_odometer - $odometer, 2)
            : null;
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(MaintenanceType::class, 'maintenance_type_id');
    }
}
