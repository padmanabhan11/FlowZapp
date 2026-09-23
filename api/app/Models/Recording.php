<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A screen recording and where it is in the pipeline (F8).
 * pending_upload → uploaded → transcribing → segmenting → generating → draft_ready | failed
 *
 * @property string $id
 * @property string $workspace_id
 * @property string|null $space_id
 * @property string|null $uploaded_by
 * @property string|null $title
 * @property string $storage_key
 * @property string|null $upload_id
 * @property string|null $mime_type
 * @property int|null $size_bytes
 * @property float|null $duration_sec
 * @property list<float>|null $scene_changes
 * @property string $state
 * @property string|null $failed_stage
 * @property string|null $failure_reason
 * @property string|null $document_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Recording extends TenantModel
{
    public const STATES = ['pending_upload', 'uploaded', 'transcribing', 'segmenting', 'generating', 'draft_ready', 'failed'];

    public const MAX_BYTES = 2 * 1024 * 1024 * 1024;   // 2 GB (F8)

    public const MAX_SECONDS = 60 * 60;                 // 60 minutes (F8)

    public const MIME_TYPES = ['video/webm', 'video/mp4', 'video/quicktime'];

    protected $fillable = [
        'space_id', 'uploaded_by', 'title', 'storage_key', 'upload_id', 'mime_type', 'size_bytes', 'duration_sec', 'scene_changes',
        'state', 'failed_stage', 'failure_reason', 'document_id',
    ];

    protected function casts(): array
    {
        return ['duration_sec' => 'float', 'size_bytes' => 'integer', 'scene_changes' => 'array'];
    }

    /**
     * @return BelongsTo<Space, $this>
     */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return HasOne<Transcript, $this>
     */
    public function transcript(): HasOne
    {
        return $this->hasOne(Transcript::class);
    }

    /**
     * @return HasMany<RecordingSegment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(RecordingSegment::class)->orderBy('position');
    }

    /**
     * @return HasMany<MediaAsset, $this>
     */
    public function assets(): HasMany
    {
        return $this->hasMany(MediaAsset::class);
    }

    /**
     * @return HasMany<PipelineJob, $this>
     */
    public function jobs(): HasMany
    {
        return $this->hasMany(PipelineJob::class);
    }

    public function inFlight(): bool
    {
        return in_array($this->state, ['transcribing', 'segmenting', 'generating'], true);
    }
}
