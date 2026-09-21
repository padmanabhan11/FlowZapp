<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FolderPermission extends TenantModel
{
    public const ROLES = ['none', 'approver', 'editor', 'reader', 'guest'];

    protected $fillable = ['folder_id', 'user_id', 'role', 'set_by'];

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
