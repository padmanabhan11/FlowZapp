<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** Nestable to depth 5 (F3). depth is maintained by the application on move.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $space_id
 * @property string|null $parent_id
 * @property string $name
 * @property int $position
 * @property int $depth
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Folder extends TenantModel
{
    public const MAX_DEPTH = 5;

    protected $fillable = ['space_id', 'parent_id', 'name', 'position', 'depth'];

    /**
     * @return BelongsTo<Space, $this>
     */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /**
     * @return BelongsTo<Folder, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Folder::class, 'parent_id');
    }

    /**
     * @return HasMany<Folder, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Folder::class, 'parent_id')->orderBy('position');
    }
}
