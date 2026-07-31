<?php

declare(strict_types=1);

namespace App\Domain\Ai\Models;

use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
