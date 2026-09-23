<?php

declare(strict_types=1);

namespace App\Audit;

use App\Models\AuditEntry;
use Illuminate\Support\Facades\Request;

final class Audit
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function record(string $action, string $entityType, ?string $entityId = null, array $metadata = []): AuditEntry
    {
        $ip = Request::ip();

        return AuditEntry::create([
            'actor_id' => Request::user()?->getKey(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'metadata' => $metadata ?: null,
            'ip' => $ip !== null ? inet_pton($ip) ?: null : null,
            'created_at' => now(),
        ]);
    }
}
