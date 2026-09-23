<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $session_id
 * @property string $role
 * @property string $content
 * @property array<mixed>|null $citations
 * @property bool $refused
 * @property int|null $latency_ms
 * @property float|null $cost_usd
 * @property bool|null $rated_helpful
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ChatMessage extends TenantModel
{
    protected $fillable = ['session_id', 'role', 'content', 'citations', 'refused', 'latency_ms', 'cost_usd', 'rated_helpful'];

    protected function casts(): array
    {
        return ['citations' => 'array', 'refused' => 'boolean', 'cost_usd' => 'float', 'rated_helpful' => 'boolean'];
    }
}
