<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Carbon;

/** One row per person per document per day (S20 "Most-read documents").
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $document_id
 * @property string $user_id
 * @property Carbon $read_on
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DocumentRead extends TenantModel
{
    protected $fillable = ['document_id', 'user_id', 'read_on'];

    protected function casts(): array
    {
        return ['read_on' => 'date'];
    }
}
