<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Steps are rows as well as content (doc 04) so chunking, citation deep links
 * (section_ref "step:7") and checkpoint state can address a step by ID.
 * version_id = '0' is the working draft; approval copies the rows under the
 * new version's id.
 */
class DocumentStep extends TenantModel
{
    protected $fillable = [
        'document_id', 'version_id', 'position', 'instruction', 'note', 'expected_result',
        'is_critical', 'is_checkpoint', 'media_asset_id', 'source_ts_start', 'source_ts_end', 'verified_at',
    ];

    protected $attributes = ['version_id' => Document::WORKING];

    protected function casts(): array
    {
        return [
            'is_critical' => 'boolean',
            'is_checkpoint' => 'boolean',
            'source_ts_start' => 'float',
            'source_ts_end' => 'float',
            'verified_at' => 'datetime',
        ];
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }
}
