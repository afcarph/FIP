<?php

declare(strict_types=1);

namespace App\Domain\User\Models;

use Illuminate\Database\Eloquent\Model;

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
