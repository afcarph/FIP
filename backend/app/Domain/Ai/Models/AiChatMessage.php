<?php

declare(strict_types=1);

namespace App\Domain\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $session_id
 * @property string $role
 * @property string $content
 * @property array<array-key, mixed>|null $tool_calls
 * @property int|null $tokens
 * @property int|null $latency_ms
 * @property Carbon|null $created_at
 * @property-read AiChatSession|null $session
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChatMessage newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChatMessage newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChatMessage query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChatMessage whereContent($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChatMessage whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChatMessage whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChatMessage whereLatencyMs($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChatMessage whereRole($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChatMessage whereSessionId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChatMessage whereTokens($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|AiChatMessage whereToolCalls($value)
 *
 * @mixin \Eloquent
 */
class AiChatMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['session_id', 'role', 'content', 'tool_calls', 'tokens', 'latency_ms'];

    protected function casts(): array
    {
        return ['tool_calls' => 'array', 'tokens' => 'integer', 'latency_ms' => 'integer'];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AiChatSession::class, 'session_id');
    }
}
