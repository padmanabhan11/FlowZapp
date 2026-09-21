<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\DocumentObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
        'content', 'approved_version_id', 'requires_ack', 'review_due_at', 'language', 'translation_of', 'translation_stale',
    ];

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

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(Folder::class);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function approvedVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'approved_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class)->orderByDesc('version_number');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(Approval::class)->orderBy('created_at');
    }

    /** Steps of the working copy, in order. */
    public function steps(): HasMany
    {
        return $this->hasMany(DocumentStep::class)->where('version_id', self::WORKING)->orderBy('position');
    }
}
