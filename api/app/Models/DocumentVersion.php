<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Immutable once written (F5). Created on approval (Epic E) and by named snapshots. */
class DocumentVersion extends TenantModel
{
    protected $fillable = ['document_id', 'version_number', 'title', 'content', 'body_text', 'change_summary', 'authored_by', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['content' => 'array', 'approved_at' => 'datetime'];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(DocumentStep::class, 'version_id')->orderBy('position');
    }
}
