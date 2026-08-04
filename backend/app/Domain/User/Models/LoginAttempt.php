<?php

declare(strict_types=1);

namespace App\Domain\User\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $email
 * @property int|null $user_id
 * @property string $ip_address
 * @property string|null $user_agent
 * @property bool $succeeded
 * @property string|null $failure_reason
 * @property Carbon $attempted_at
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginAttempt newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginAttempt newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginAttempt query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginAttempt whereAttemptedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginAttempt whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginAttempt whereFailureReason($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginAttempt whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginAttempt whereIpAddress($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginAttempt whereSucceeded($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginAttempt whereUserAgent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|LoginAttempt whereUserId($value)
 *
 * @mixin \Eloquent
 */
class LoginAttempt extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'email', 'user_id', 'ip_address', 'user_agent', 'succeeded', 'failure_reason', 'attempted_at',
    ];

    protected function casts(): array
    {
        return ['succeeded' => 'boolean', 'attempted_at' => 'datetime'];
    }

    public static function record(string $email, ?int $userId, bool $succeeded, ?string $reason = null): void
    {
        $request = request();

        static::create([
            'email' => mb_strtolower($email),
            'user_id' => $userId,
            'ip_address' => $request?->ip() ? inet_pton($request->ip()) : inet_pton('0.0.0.0'),
            'user_agent' => substr((string) $request?->userAgent(), 0, 255) ?: null,
            'succeeded' => $succeeded,
            'failure_reason' => $reason,
            'attempted_at' => now(),
        ]);
    }
}
