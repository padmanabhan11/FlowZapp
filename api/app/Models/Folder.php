<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Nestable to depth 5 (F3). depth is maintained by the application on move. */
class Folder extends TenantModel
{
    public const MAX_DEPTH = 5;

    protected $fillable = ['space_id', 'parent_id', 'name', 'position', 'depth'];

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Folder::class, 'parent_id')->orderBy('position');
    }
}
