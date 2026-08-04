<?php

declare(strict_types=1);

namespace App\Domain\User\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $device_uuid
 * @property string|null $device_name
 * @property string $platform
 * @property string|null $fcm_token
 * @property string|null $biometric_key
 * @property Carbon|null $last_seen_at
 * @property bool $is_trusted
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice pushable()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice whereBiometricKey($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice whereDeviceName($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice whereDeviceUuid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice whereFcmToken($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice whereIsTrusted($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice whereLastSeenAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice wherePlatform($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|UserDevice whereUserId($value)
 *
 * @mixin \Eloquent
 */
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

    public function scopePushable(Builder $query): Builder
    {
        return $query->whereNotNull('fcm_token');
    }
}
