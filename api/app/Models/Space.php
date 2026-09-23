<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** A department or team; the primary permission boundary inside a workspace (F3).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property string|null $description
 * @property bool $is_handbook
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Space extends TenantModel
{
    protected $fillable = ['name', 'description', 'is_handbook', 'created_by'];

    protected function casts(): array
    {
        return ['is_handbook' => 'boolean'];
    }

    /**
     * @return HasMany<SpaceMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(SpaceMember::class);
    }

    /**
     * @return HasMany<Folder, $this>
     */
    public function folders(): HasMany
    {
        return $this->hasMany(Folder::class);
    }
}
