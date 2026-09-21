<?php

declare(strict_types=1);

namespace App\Models;

/** Authoritative record of what should be in the vector index, so drift is detectable (doc 04). */
class DocumentChunk extends TenantModel
{
    protected $fillable = ['space_id', 'document_id', 'version_id', 'folder_id', 'section_ref', 'heading_path', 'content', 'token_count', 'vector_id', 'embed_model', 'indexed_at'];

    protected function casts(): array
    {
        return ['indexed_at' => 'datetime'];
    }
}
