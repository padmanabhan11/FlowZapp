<?php

declare(strict_types=1);

namespace App\Models;

class ChatMessage extends TenantModel
{
    protected $fillable = ['session_id', 'role', 'content', 'citations', 'refused', 'latency_ms', 'rated_helpful'];

    protected function casts(): array
    {
        return ['citations' => 'array', 'refused' => 'boolean', 'rated_helpful' => 'boolean'];
    }
}
