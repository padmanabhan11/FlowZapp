<?php

declare(strict_types=1);

namespace App\Models;

/** Frames, images, attachments and videos in DO Spaces. Always private; served by signed URL only (03 §11). */
class MediaAsset extends TenantModel
{
    protected $fillable = ['recording_id', 'document_id', 'kind', 'storage_key', 'mime_type', 'size_bytes', 'width', 'height'];
}
