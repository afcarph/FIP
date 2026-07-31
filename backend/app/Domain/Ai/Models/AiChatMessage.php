<?php

declare(strict_types=1);

namespace App\Domain\Ai\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
