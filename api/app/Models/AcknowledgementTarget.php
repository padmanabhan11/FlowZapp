<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Who must acknowledge a document (FR-702). Version-independent; the record is per version. */
class AcknowledgementTarget extends TenantModel
{
    protected $fillable = ['document_id', 'user_id', 'assigned_by'];

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
