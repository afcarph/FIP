<?php

declare(strict_types=1);

namespace App\Domain\Station\Models;

use App\Support\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Brand extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = ['code', 'name', 'logo_path', 'website', 'color_hex', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function stations(): HasMany
    {
        return $this->hasMany(GasStation::class);
    }
}
