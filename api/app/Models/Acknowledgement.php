<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** A person confirmed they read a specific approved version (FR-703, BRL-07). Unique per (version, user).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $document_id
 * @property string $version_id
 * @property string $user_id
 * @property Carbon $acknowledged_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Acknowledgement extends TenantModel
{
    protected $fillable = ['document_id', 'version_id', 'user_id', 'acknowledged_at'];

    protected function casts(): array
    {
        return ['acknowledged_at' => 'datetime'];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'version_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
