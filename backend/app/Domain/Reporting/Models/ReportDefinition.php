<?php

declare(strict_types=1);

namespace App\Domain\Reporting\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReportDefinition extends Model
{
    protected $fillable = ['code', 'name', 'description', 'scope', 'default_params', 'required_permission'];

    protected function casts(): array
    {
        return ['default_params' => 'array'];
    }

    public function runs(): HasMany
    {
        return $this->hasMany(ReportRun::class);
    }
}
