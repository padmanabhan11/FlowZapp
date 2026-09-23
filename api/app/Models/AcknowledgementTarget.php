<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** Who must acknowledge a document (FR-702). Version-independent; the record is per version.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $document_id
 * @property string $user_id
 * @property string|null $assigned_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AcknowledgementTarget extends TenantModel
{
    protected $fillable = ['document_id', 'user_id', 'assigned_by'];

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
