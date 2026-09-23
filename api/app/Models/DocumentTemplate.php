<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A workspace-defined template (B5-T3). Built-ins live in App\Documents\Templates. */
class DocumentTemplate extends TenantModel
{
    protected $fillable = ['name', 'description', 'doc_type', 'content', 'steps', 'created_by'];

    protected function casts(): array
    {
        return ['content' => 'array', 'steps' => 'array'];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
