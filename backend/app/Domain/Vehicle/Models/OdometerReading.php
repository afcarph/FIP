<?php

declare(strict_types=1);

namespace App\Domain\Vehicle\Models;

use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OdometerReading extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['vehicle_id', 'reading', 'source', 'recorded_at', 'recorded_by', 'photo_path'];

    protected function casts(): array
    {
        return ['reading' => 'decimal:2', 'recorded_at' => 'datetime'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
