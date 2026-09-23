<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $folder_id
 * @property string $user_id
 * @property string $role
 * @property string|null $set_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class FolderPermission extends TenantModel
{
    public const ROLES = ['none', 'approver', 'editor', 'reader', 'guest'];

    protected $fillable = ['folder_id', 'user_id', 'role', 'set_by'];

    /**
     * @return BelongsTo<Folder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function setter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'set_by');
    }
}
