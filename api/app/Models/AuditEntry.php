<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Carbon;

/**
 * Append-only. No updated_at; never updated or deleted through the model.
 * Write through App\Audit\Audit::record().
 *
 * @property int $id
 * @property string $workspace_id
 * @property string|null $actor_id
 * @property string $action
 * @property string $entity_type
 * @property string|null $entity_id
 * @property array<string, mixed>|null $metadata
 * @property string|null $ip
 * @property Carbon|null $created_at
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

    /**
     * audit_log uses a bigint auto-increment key, not a ULID (doc 04).
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return [];
    }
}
