<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Append-only. No updated_at; never updated or deleted through the model.
 * Write through App\Audit\Audit::record().
 */
class AuditEntry extends TenantModel
{
    protected $table = 'audit_log';

    public $timestamps = false;

    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = ['actor_id', 'action', 'entity_type', 'entity_id', 'metadata', 'ip', 'created_at'];

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime'];
    }

    // audit_log uses a bigint auto-increment key, not a ULID (doc 04).
    public function uniqueIds(): array
    {
        return [];
    }
}
