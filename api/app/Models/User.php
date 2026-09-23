<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * Global table: a person can belong to many workspaces (FR-108).
 * Password is nullable — sign-in is by magic link (FR-101).
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string|null $password
 * @property string $locale
 * @property string|null $avatar_path
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasUlids, Notifiable;

    protected $fillable = ['name', 'email', 'locale', 'avatar_path'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * @return BelongsToMany<Workspace, $this>
     */
    public function workspaces(): BelongsToMany
    {
        return $this->belongsToMany(Workspace::class, 'workspace_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Role in a workspace, or null when not a member. Used by ResolveWorkspace
     * to refuse a header naming a workspace the caller is not in.
     */
    public function roleIn(string $workspaceId): ?string
    {
        /**
         * @var WorkspaceMember|null $m
         */
        $m = WorkspaceMember::withoutGlobalScopes() // allowlisted: membership lookup precedes tenant resolution
            ->where('workspace_id', $workspaceId)
            ->where('user_id', $this->getKey())
            ->first();

        return $m?->role;
    }
}
