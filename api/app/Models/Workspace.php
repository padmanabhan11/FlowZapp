<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The tenant root. Global table (no workspace_id) — on the conformance-test
 * allowlist by construction, since it *is* the workspace.
 *
 * @property string $id
 * @property string $name
 * @property string $slug
 * @property string $plan
 * @property array<string, mixed> $settings
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deletion_scheduled_at
 */
class Workspace extends Model
{
    use HasUlids;

    protected $fillable = ['name', 'slug', 'plan', 'settings', 'deletion_scheduled_at'];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'deletion_scheduled_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<WorkspaceMember, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return HasMany<Space, $this>
     */
    public function spaces(): HasMany
    {
        return $this->hasMany(Space::class);
    }
}
