<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Carbon;

/** Frames, images, attachments and videos in DO Spaces. Always private; served by signed URL only (03 §11).
 *
 * @property string $id
 * @property string $workspace_id
 * @property string|null $recording_id
 * @property string|null $document_id
 * @property string $kind
 * @property string $storage_key
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property int|null $width
 * @property int|null $height
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class MediaAsset extends TenantModel
{
    protected $fillable = ['recording_id', 'document_id', 'kind', 'storage_key', 'mime_type', 'size_bytes', 'width', 'height'];
}
