<?php

declare(strict_types=1);

namespace App\Models;

/**
 * token_hash holds a SHA-256 of the invite token; the raw token exists only
 * in the emailed link (04-Database-Schema).
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
