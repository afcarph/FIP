<?php

declare(strict_types=1);

namespace App\Domain\Ai\Models;

use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string|null $title
 * @property array<array-key, mixed>|null $context
 * @property int $token_usage
 * @property Carbon|null $last_message_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, AiChatMessage> $messages
 * @property-read int|null $messages_count
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChatSession newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChatSession newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChatSession onlyTrashed()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChatSession query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChatSession whereContext($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChatSession whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChatSession whereDeletedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChatSession whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChatSession whereLastMessageAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChatSession whereTitle($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChatSession whereTokenUsage($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChatSession whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChatSession whereUserId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChatSession withTrashed(bool $withTrashed = true)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChatSession withoutTrashed()
 *
 * @mixin \Eloquent
 */
class AiChatSession extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = ['user_id', 'title', 'context', 'token_usage', 'last_message_at'];

    protected function casts(): array
    {
        return ['context' => 'array', 'token_usage' => 'integer', 'last_message_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<AiChatMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(AiChatMessage::class, 'session_id');
    }

    /** Trailing turns replayed to the model, oldest first. */
    public function transcript(int $limit = 20): array
    {
        return $this->messages()
            ->latest('id')
            ->limit($limit)
            ->get(['role', 'content'])
            ->reverse()
            ->map(fn (AiChatMessage $m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->all();
    }
}
