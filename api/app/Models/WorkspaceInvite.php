<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Carbon;

/**
 * token_hash holds a SHA-256 of the invite token; the raw token exists only
 * in the emailed link (04-Database-Schema).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $email
 * @property string $role
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon|null $revoked_at
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property array<mixed>|null $space_ids
 */
class WorkspaceInvite extends TenantModel
{
    protected $fillable = ['email', 'role', 'space_ids', 'token_hash', 'expires_at', 'accepted_at', 'revoked_at', 'created_by'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'space_ids' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->accepted_at === null && $this->revoked_at === null && $this->expires_at->isFuture();
    }
}
