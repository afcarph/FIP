<?php

declare(strict_types=1);

namespace App\Domain\User\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $provider
 * @property string $provider_uid
 * @property string|null $email
 * @property array<array-key, mixed>|null $raw_payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 *
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OauthAccount newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OauthAccount newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OauthAccount query()
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OauthAccount whereCreatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OauthAccount whereEmail($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OauthAccount whereId($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OauthAccount whereProvider($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OauthAccount whereProviderUid($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OauthAccount whereRawPayload($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OauthAccount whereUpdatedAt($value)
 * @method static \Illuminate\Database\Eloquent\Builder<static>|OauthAccount whereUserId($value)
 *
 * @mixin \Eloquent
 */
class OauthAccount extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'provider', 'provider_uid', 'email', 'raw_payload'];

    protected $hidden = ['raw_payload'];

    protected function casts(): array
    {
        return ['raw_payload' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
