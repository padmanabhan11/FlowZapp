<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Carbon;

/** Authoritative record of what should be in the vector index, so drift is detectable (doc 04).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $space_id
 * @property string $document_id
 * @property string $version_id
 * @property string|null $folder_id
 * @property string $section_ref
 * @property string|null $heading_path
 * @property string $content
 * @property int|null $token_count
 * @property string|null $vector_id
 * @property string|null $embed_model
 * @property Carbon|null $indexed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DocumentChunk extends TenantModel
{
    protected $fillable = ['space_id', 'document_id', 'version_id', 'folder_id', 'section_ref', 'heading_path', 'content', 'token_count', 'vector_id', 'embed_model', 'indexed_at'];

    protected function casts(): array
    {
        return ['indexed_at' => 'datetime'];
    }
}
