<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $user_id
 * @property string $role
 * @property string|null $invited_by
 * @property Carbon|null $joined_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property array<string, mixed>|null $notification_prefs
 */
class WorkspaceMember extends TenantModel
{
    public const ROLES = ['admin', 'approver', 'editor', 'reader', 'guest'];

    protected $fillable = ['user_id', 'role', 'invited_by', 'joined_at', 'notification_prefs'];

    protected function casts(): array
    {
        return ['joined_at' => 'datetime', 'notification_prefs' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
