<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $user_id
 * @property string|null $title
 * @property string|null $scope_document_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ChatSession extends TenantModel
{
    protected $fillable = ['user_id', 'title', 'scope_document_id'];

    /**
     * @return HasMany<ChatMessage, $this>
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'session_id')->orderBy('created_at');
    }
}
