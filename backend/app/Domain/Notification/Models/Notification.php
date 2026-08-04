<?php

declare(strict_types=1);

namespace App\Domain\Notification\Models;

use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property string $type
 * @property string $category
 * @property string $title
 * @property string $body
 * @property array<array-key, mixed>|null $data
 * @property string|null $action_url
 * @property string $priority
 * @property Carbon|null $read_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 *
 * @method static Builder<static>|Notification category(string $category)
 * @method static Builder<static>|Notification newModelQuery()
 * @method static Builder<static>|Notification newQuery()
 * @method static Builder<static>|Notification query()
 * @method static Builder<static>|Notification unread()
 * @method static Builder<static>|Notification whereActionUrl($value)
 * @method static Builder<static>|Notification whereBody($value)
 * @method static Builder<static>|Notification whereCategory($value)
 * @method static Builder<static>|Notification whereCreatedAt($value)
 * @method static Builder<static>|Notification whereData($value)
 * @method static Builder<static>|Notification whereId($value)
 * @method static Builder<static>|Notification wherePriority($value)
 * @method static Builder<static>|Notification whereReadAt($value)
 * @method static Builder<static>|Notification whereSentAt($value)
 * @method static Builder<static>|Notification whereTitle($value)
 * @method static Builder<static>|Notification whereType($value)
 * @method static Builder<static>|Notification whereUpdatedAt($value)
 * @method static Builder<static>|Notification whereUserId($value)
 *
 * @mixin \Eloquent
 */
class Notification extends Model
{
    use HasUuids;

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id', 'user_id', 'type', 'category', 'title', 'body',
        'data', 'action_url', 'priority', 'read_at', 'sent_at',
    ];

    protected function casts(): array
    {
        return ['data' => 'array', 'read_at' => 'datetime', 'sent_at' => 'datetime'];
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    public function markRead(): void
    {
        if ($this->read_at === null) {
            $this->forceFill(['read_at' => now()])->save();
        }
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
