<?php

declare(strict_types=1);

namespace App\Domain\User\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserDevice extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'device_uuid', 'device_name', 'platform', 'fcm_token',
        'biometric_key', 'last_seen_at', 'is_trusted',
    ];

    protected $hidden = ['biometric_key'];

    protected function casts(): array
    {
        return ['last_seen_at' => 'datetime', 'is_trusted' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopePushable($query)
    {
        return $query->whereNotNull('fcm_token');
    }
}
