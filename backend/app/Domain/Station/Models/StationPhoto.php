<?php

declare(strict_types=1);

namespace App\Domain\Station\Models;

use App\Domain\User\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class StationPhoto extends Model
{
    use SoftDeletes;

    protected $fillable = ['station_id', 'user_id', 'path', 'caption', 'is_primary', 'status'];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function station(): BelongsTo
    {
        return $this->belongsTo(GasStation::class, 'station_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /** Signed URL so private bucket objects stay private. */
    public function getUrlAttribute(): ?string
    {
        return $this->path === null ? null : Storage::temporaryUrl($this->path, now()->addHour());
    }
}
