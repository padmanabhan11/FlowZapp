<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Carbon;

/** Idempotency record for pipeline stages: job_key = {recording_id}:{stage}:{input_hash} (03 §5). bigint key, not ULID.
 *
 * @property int $id
 * @property string $workspace_id
 * @property string|null $recording_id
 * @property string|null $document_id
 * @property string $stage
 * @property string $job_key
 * @property string $status
 * @property int $attempts
 * @property float|null $cost_usd
 * @property string|null $error
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PipelineJob extends TenantModel
{
    public $incrementing = true;

    protected $keyType = 'int';

    protected $fillable = ['recording_id', 'document_id', 'stage', 'job_key', 'status', 'attempts', 'cost_usd', 'error', 'started_at', 'finished_at'];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'cost_usd' => 'float'];
    }

    /**
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return [];
    }
}
