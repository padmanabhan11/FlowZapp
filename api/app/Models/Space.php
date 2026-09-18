<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/** A department or team; the primary permission boundary inside a workspace (F3). */
class Space extends TenantModel
{
    protected $fillable = ['name', 'description', 'is_handbook', 'created_by'];

    protected function casts(): array
    {
        return ['is_handbook' => 'boolean'];
    }

    public function members(): HasMany
    {
        return $this->hasMany(SpaceMember::class);
    }

    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class);
    }
}
