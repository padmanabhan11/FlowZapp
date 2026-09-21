<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A screen recording and where it is in the pipeline (F8).
 * pending_upload → uploaded → transcribing → segmenting → generating → draft_ready | failed
 */
class Recording extends TenantModel
{
    public const STATES = ['pending_upload', 'uploaded', 'transcribing', 'segmenting', 'generating', 'draft_ready', 'failed'];

    public const MAX_BYTES = 2 * 1024 * 1024 * 1024;   // 2 GB (F8)

    public const MAX_SECONDS = 60 * 60;                 // 60 minutes (F8)

    public const MIME_TYPES = ['video/webm', 'video/mp4', 'video/quicktime'];

    protected $fillable = [
        'space_id', 'uploaded_by', 'title', 'storage_key', 'upload_id', 'mime_type', 'size_bytes', 'duration_sec',
        'state', 'failed_stage', 'failure_reason', 'document_id',
    ];

    protected function casts(): array
    {
        return ['duration_sec' => 'float', 'size_bytes' => 'integer'];
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    public function transcript(): HasOne
    {
        return $this->hasOne(Transcript::class);
    }

    public function segments(): HasMany
    {
        return $this->hasMany(RecordingSegment::class)->orderBy('position');
    }

    public function assets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(PipelineJob::class);
    }

    public function inFlight(): bool
    {
        return in_array($this->state, ['transcribing', 'segmenting', 'generating'], true);
    }
}
