<?php

declare(strict_types=1);

namespace App\Governance;

use App\Audit\Audit;
use App\Documents\Content;
use App\Jobs\Pipeline\IndexDocument;
use App\Models\Approval;
use App\Models\Document;
use App\Models\DocumentStep;
use App\Models\DocumentVersion;
use App\Models\SpaceMember;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\AcknowledgementDueNotification;
use App\Notifications\ChangesRequestedNotification;
use App\Notifications\DocumentApprovedNotification;
use App\Notifications\ReviewRequestedNotification;
use App\Retrieval\Deindex;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The approval workflow (F6): draft → in_review → approved → archived.
 * Every transition writes an approvals row and an audit entry. Approval
 * creates an immutable version (F5) and never touches the working copy.
 */
final class Workflow
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /** draft → in_review (author, owner, or space editor+). */
    public function submit(Document $doc, User $actor, ?string $reviewerId = null): Document
    {
        $this->assertState($doc, ['draft']);
        $blockers = $this->submitBlockers($doc);
        if ($blockers !== []) {
            throw new HttpException(422, 'Cannot submit yet: '.implode('; ', $blockers).'.');
        }

        return DB::transaction(function () use ($doc, $actor, $reviewerId): Document {
            $doc->forceFill(['state' => 'in_review', 'submitted_by' => $actor->id, 'submitted_at' => now()])->save();
            Approval::create(['document_id' => $doc->id, 'requested_by' => $actor->id, 'reviewer_id' => $reviewerId, 'from_state' => 'draft', 'to_state' => 'in_review']);
            Audit::record('document.submitted', 'document', $doc->id);
            $this->notifyReviewers($doc, $actor);

            return $doc;
        });
    }

    /** @return list<string> */
    public function submitBlockers(Document $doc): array
    {
        $b = [];
        if ($doc->owner_id === null) {
            $b[] = 'an owner is required';
        }
        if (trim((string) ($doc->content['purpose'] ?? '')) === '') {
            $b[] = 'a purpose is required';
        }
        $steps = $doc->steps()->get();
        if ($doc->doc_type === 'sop' && $steps->isEmpty()) {
            $b[] = 'an SOP needs at least one step';
        }
        if ($doc->source_recording_id !== null) {
            $unverified = $steps->whereNull('verified_at')->count();
            if ($unverified > 0) {
                $b[] = "$unverified generated step(s) still need to be verified against the recording";  // FR-315
            }
        }

        return $b;
    }

    /** in_review → approved (space approver or admin; not the submitter unless self-approval is enabled). */
    public function approve(Document $doc, User $reviewer, ?string $changeSummary = null, ?string $expectedUpdatedAt = null): DocumentVersion
    {
        $this->assertState($doc, ['in_review']);
        if ($expectedUpdatedAt !== null && ! Carbon::parse($expectedUpdatedAt)->equalTo($doc->updated_at)) {
            throw new HttpException(409, 'This document changed while you were reviewing it. Reload to see the current version before approving.');
        }
        $workspace = Workspace::query()->findOrFail($this->current->require());
        if ($doc->submitted_by === $reviewer->id && ! (bool) ($workspace->settings['self_approval'] ?? false)) {
            throw new HttpException(403, 'You submitted this. Someone else needs to approve it.');
        }
        if ($doc->doc_type === 'sop' && $doc->steps()->count() === 0) {
            throw new HttpException(422, 'An SOP with no steps cannot be approved.');
        }

        return DB::transaction(function () use ($doc, $reviewer, $changeSummary, $workspace): DocumentVersion {
            // Row lock + unique key make concurrent approvals safe (doc 04): the loser gets a duplicate-key error.
            $number = (int) DocumentVersion::query()->where('document_id', $doc->id)->lockForUpdate()->max('version_number') + 1;
            $steps = $doc->steps()->get();
            $content = Content::merge(Content::empty(), $doc->content ?? []);
            $content['_steps'] = $steps->map(fn (DocumentStep $s) => Snapshot::step($s, $s->id))->all();

            $version = DocumentVersion::create([
                'document_id' => $doc->id, 'version_number' => $number, 'title' => $doc->title, 'content' => $content,
                'body_text' => $doc->body_text, 'change_summary' => $changeSummary, 'authored_by' => $doc->submitted_by ?? $doc->created_by,
                'approved_by' => $reviewer->id, 'approved_at' => now(),
            ]);
            foreach ($steps as $s) {
                DocumentStep::create($s->only(['document_id', 'position', 'instruction', 'note', 'expected_result', 'is_critical', 'is_checkpoint', 'media_asset_id', 'source_ts_start', 'source_ts_end', 'verified_at']) + ['version_id' => $version->id]);
            }

            $cadence = $workspace->settings['review_cadence_months'] ?? null;
            $doc->forceFill([
                'state' => 'approved', 'approved_version_id' => $version->id,
                'review_due_at' => $cadence ? now()->addMonths((int) $cadence) : $doc->review_due_at,
                'translation_stale' => false,
            ])->save();
            Document::query()->where('translation_of', $doc->id)->update(['translation_stale' => true]);   // I3
            Approval::create(['document_id' => $doc->id, 'requested_by' => $doc->submitted_by, 'reviewer_id' => $reviewer->id, 'from_state' => 'in_review', 'to_state' => 'approved', 'comment' => $changeSummary]);
            Audit::record('document.approved', 'document', $doc->id, ['version' => $number]);
            IndexDocument::dispatch($doc->workspace_id, $doc->id, $version->id);   // stage 5, on approval only
            if ($doc->requires_ack) {   // FR-704: a new version re-obtains acknowledgement from every target
                foreach ($doc->ackTargets()->with('user')->get() as $t) {
                    $t->user?->notify(new AcknowledgementDueNotification($doc->title, $number, $doc->id));
                }
            }
            if ($doc->submitted_by && $doc->submitted_by !== $reviewer->id) {
                User::query()->find($doc->submitted_by)?->notify(new DocumentApprovedNotification($doc->title, $number, $doc->id));
            }

            return $version;
        });
    }

    /** in_review → draft with a required comment (space approver or admin). */
    public function requestChanges(Document $doc, User $reviewer, string $comment): Document
    {
        $this->assertState($doc, ['in_review']);

        return DB::transaction(function () use ($doc, $reviewer, $comment): Document {
            $doc->forceFill(['state' => 'draft'])->save();
            Approval::create(['document_id' => $doc->id, 'requested_by' => $doc->submitted_by, 'reviewer_id' => $reviewer->id, 'from_state' => 'in_review', 'to_state' => 'draft', 'comment' => $comment]);
            Audit::record('document.changes_requested', 'document', $doc->id);
            if ($doc->submitted_by) {
                User::query()->find($doc->submitted_by)?->notify(new ChangesRequestedNotification($doc->title, $comment, $doc->id));
            }

            return $doc;
        });
    }

    /** any → archived (space approver or admin). Leaves retrieval in the same transaction from M3. */
    public function archive(Document $doc, User $actor): Document
    {
        $this->assertState($doc, ['draft', 'in_review', 'approved']);

        return DB::transaction(function () use ($doc, $actor): Document {
            $from = $doc->state;
            $doc->forceFill(['state' => 'archived'])->save();
            Deindex::document($doc->workspace_id, $doc->id);   // same transaction as the state change (FR-615)
            Approval::create(['document_id' => $doc->id, 'requested_by' => $actor->id, 'reviewer_id' => $actor->id, 'from_state' => $from, 'to_state' => 'archived']);
            Audit::record('document.archived', 'document', $doc->id);

            return $doc;
        });
    }

    /** Restore an earlier version as a new draft; the live approved version is untouched (F5). */
    public function restore(Document $doc, DocumentVersion $version, User $actor): Document
    {
        if ($doc->state === 'archived') {
            throw new HttpException(409, 'Unarchive the document before restoring a version.');
        }

        return DB::transaction(function () use ($doc, $version, $actor): Document {
            $content = $version->content ?? [];
            $steps = $content['_steps'] ?? [];
            unset($content['_steps']);
            $doc->steps()->delete();
            foreach (array_values($steps) as $i => $s) {
                DocumentStep::create([
                    'document_id' => $doc->id, 'position' => $i + 1, 'instruction' => $s['instruction'] ?? '', 'note' => $s['note'] ?? null,
                    'expected_result' => $s['expected_result'] ?? null, 'is_critical' => (bool) ($s['is_critical'] ?? false), 'is_checkpoint' => (bool) ($s['is_checkpoint'] ?? false),
                    'media_asset_id' => $s['media_asset_id'] ?? null, 'source_ts_start' => $s['source_ts_start'] ?? null, 'source_ts_end' => $s['source_ts_end'] ?? null, 'verified_at' => now(),
                ]);
            }
            $doc->unsetRelation('steps');
            $doc->forceFill(['title' => $version->title, 'content' => Content::merge(Content::empty(), $content), 'state' => 'draft'])->save();
            Approval::create(['document_id' => $doc->id, 'requested_by' => $actor->id, 'reviewer_id' => null, 'from_state' => 'approved', 'to_state' => 'draft', 'comment' => "Restored from v{$version->version_number}"]);
            Audit::record('document.restored', 'document', $doc->id, ['version' => $version->version_number]);

            return $doc->refresh();
        });
    }

    private function assertState(Document $doc, array $allowed): void
    {
        if (! in_array($doc->state, $allowed, true)) {
            throw new HttpException(409, "This action is not available while the document is {$doc->state}.");
        }
    }

    private function notifyReviewers(Document $doc, User $submitter): void
    {
        $ids = SpaceMember::query()->where('space_id', $doc->space_id)->whereIn('role', ['admin', 'approver'])->pluck('user_id')
            ->push($doc->owner_id)->filter()->unique()->reject(fn ($id) => $id === $submitter->id);
        $users = User::query()->whereIn('id', $ids)->get();
        if ($users->isNotEmpty()) {
            Notification::send($users, new ReviewRequestedNotification($doc->title, $submitter->name, $doc->id));
        }
    }
}
