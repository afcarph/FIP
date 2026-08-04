<?php

declare(strict_types=1);

namespace App\Domain\User\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * Append-only audit trail. The model deliberately has no `updated_at` and
 * blocks mutation so that a compromised application cannot rewrite history.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int|null $impersonator_id
 * @property string $event
 * @property string $auditable_type
 * @property int|null $auditable_id
 * @property array<array-key, mixed>|null $old_values
 * @property array<array-key, mixed>|null $new_values
 * @property string|null $url
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property Carbon $created_at
 * @property-read Model|\Eloquent|null $auditable
 * @property-read User|null $user
 *
 * @method static Builder<static>|AuditLog event(string $event)
 * @method static Builder<static>|AuditLog forSubject(string $type, int $id)
 * @method static Builder<static>|AuditLog newModelQuery()
 * @method static Builder<static>|AuditLog newQuery()
 * @method static Builder<static>|AuditLog query()
 * @method static Builder<static>|AuditLog whereAuditableId($value)
 * @method static Builder<static>|AuditLog whereAuditableType($value)
 * @method static Builder<static>|AuditLog whereCreatedAt($value)
 * @method static Builder<static>|AuditLog whereEvent($value)
 * @method static Builder<static>|AuditLog whereId($value)
 * @method static Builder<static>|AuditLog whereImpersonatorId($value)
 * @method static Builder<static>|AuditLog whereIpAddress($value)
 * @method static Builder<static>|AuditLog whereNewValues($value)
 * @method static Builder<static>|AuditLog whereOldValues($value)
 * @method static Builder<static>|AuditLog whereRequestId($value)
 * @method static Builder<static>|AuditLog whereUrl($value)
 * @method static Builder<static>|AuditLog whereUserAgent($value)
 * @method static Builder<static>|AuditLog whereUserId($value)
 *
 * @mixin \Eloquent
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'user_id', 'impersonator_id', 'event', 'auditable_type', 'auditable_id',
        'old_values', 'new_values', 'url', 'ip_address', 'user_agent', 'request_id',
    ];

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(static fn () => throw new \LogicException('Audit log entries are immutable.'));
        static::deleting(static fn () => throw new \LogicException('Audit log entries cannot be deleted.'));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function getIpAddressAttribute(?string $value): ?string
    {
        return $value === null ? null : (inet_ntop($value) ?: null);
    }

    public function scopeEvent(Builder $query, string $event): Builder
    {
        return $query->where('event', $event);
    }

    public function scopeForSubject(Builder $query, string $type, int $id): Builder
    {
        return $query->where('auditable_type', $type)->where('auditable_id', $id);
    }
}
