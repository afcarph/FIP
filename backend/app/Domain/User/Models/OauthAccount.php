<?php

declare(strict_types=1);

namespace App\Domain\User\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
