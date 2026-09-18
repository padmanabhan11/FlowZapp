<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceMember extends TenantModel
{
    public const ROLES = ['admin', 'approver', 'editor', 'reader', 'guest'];

    protected $fillable = ['user_id', 'role', 'invited_by', 'joined_at'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
