<?php

declare(strict_types=1);

namespace App\Models;

/** Idempotency record for pipeline stages: job_key = {recording_id}:{stage}:{input_hash} (03 §5). bigint key, not ULID. */
class PipelineJob extends TenantModel
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = ['recording_id', 'document_id', 'stage', 'job_key', 'status', 'attempts', 'cost_usd', 'error', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'cost_usd' => 'float'];
    }

    public function uniqueIds(): array
    {
        return [];
    }
}
