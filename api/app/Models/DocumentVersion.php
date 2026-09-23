<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** Immutable once written (F5). Created on approval (Epic E) and by named snapshots.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $document_id
 * @property int $version_number
 * @property string $title
 * @property array<string, mixed> $content
 * @property string|null $body_text
 * @property string|null $change_summary
 * @property string|null $authored_by
 * @property string|null $approved_by
 * @property Carbon|null $approved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DocumentVersion extends TenantModel
{
    protected $fillable = ['document_id', 'version_number', 'title', 'content', 'body_text', 'change_summary', 'authored_by', 'approved_by', 'approved_at'];

    protected function casts(): array
    {
        return ['content' => 'array', 'approved_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return HasMany<DocumentStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(DocumentStep::class, 'version_id')->orderBy('position');
    }
}
