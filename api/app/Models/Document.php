<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\DocumentObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $space_id
 * @property string|null $folder_id
 * @property string $title
 * @property string $doc_type
 * @property string $state
 * @property string|null $owner_id
 * @property string|null $created_by
 * @property string|null $source_recording_id
 * @property array<string, mixed> $content
 * @property string|null $body_text
 * @property string|null $approved_version_id
 * @property bool $requires_ack
 * @property Carbon|null $review_due_at
 * @property string $language
 * @property string|null $translation_of
 * @property bool $translation_stale
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property string|null $submitted_by
 * @property Carbon|null $submitted_at
 * @property int|null $handbook_position
 */
#[ObservedBy(DocumentObserver::class)]
class Document extends TenantModel
{
    use SoftDeletes;

    public const TYPES = ['sop', 'policy', 'handbook_page', 'note'];

    public const STATES = ['draft', 'in_review', 'approved', 'archived'];

    /** version_id sentinel for the working copy's steps (doc 04). */
    public const WORKING = '0';

    protected $fillable = [
        'space_id', 'folder_id', 'title', 'doc_type', 'state', 'owner_id', 'submitted_by', 'submitted_at', 'created_by', 'source_recording_id',
        'content', 'approved_version_id', 'requires_ack', 'handbook_position', 'review_due_at', 'language', 'translation_of', 'translation_stale',
    ];

    /**
     * Documents with a live approved version — what readers, search and the
     * assistant see. Editing an approved document moves it back to draft while
     * the approved version stays live (non-negotiable 5), so "state = approved"
     * alone would hide every document that is being revised. Archived is never live.
     *
     * @param  Builder<Document>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNotNull('approved_version_id')->where('state', '!=', 'archived');
    }

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'requires_ack' => 'boolean',
            'translation_stale' => 'boolean',
            'review_due_at' => 'datetime',
            'submitted_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Space, $this>
     */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    /**
     * @return BelongsTo<Folder, $this>
     */
    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function approvedVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'approved_version_id');
    }

    /**
     * @return HasMany<DocumentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version_number');
    }

    /**
     * @return HasMany<AcknowledgementTarget, $this>
     */
    public function ackTargets(): HasMany
    {
        return $this->hasMany(AcknowledgementTarget::class);
    }

    /**
     * @return HasMany<Approval, $this>
     */
    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class)->orderBy('created_at');
    }

    /**
     * The document this one is a translation of (FR-803).
     *
     * @return BelongsTo<Document, $this>
     */
    public function source(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'translation_of');
    }

    /**
     * Translations of this document, one per language (I2-T3: the link reads both ways).
     *
     * @return HasMany<Document, $this>
     */
    public function translations(): HasMany
    {
        return $this->hasMany(Document::class, 'translation_of')->orderBy('language');
    }

    /**
     * Steps of the working copy, in order.
     *
     * @return HasMany<DocumentStep, $this>
     */
    public function steps(): HasMany
    {
        return $this->hasMany(DocumentStep::class)->where('version_id', self::WORKING)->orderBy('position');
    }
}
