<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** A workspace-defined template (B5-T3). Built-ins live in App\Documents\Templates.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property string|null $description
 * @property string $doc_type
 * @property array<string, mixed> $content
 * @property array<mixed> $steps
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DocumentTemplate extends TenantModel
{
    protected $fillable = ['name', 'description', 'doc_type', 'content', 'steps', 'created_by'];

    protected function casts(): array
    {
        return ['content' => 'array', 'steps' => 'array'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
