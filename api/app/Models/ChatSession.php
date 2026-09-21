<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatSession extends TenantModel
{
    protected $fillable = ['user_id', 'title', 'scope_document_id'];

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'session_id')->orderBy('created_at');
    }
}
