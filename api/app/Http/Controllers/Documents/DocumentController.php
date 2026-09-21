<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Audit\Audit;
use App\Documents\Content;
use App\Documents\Templates;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentStep;
use App\Models\Folder;
use App\Models\Space;
use App\Models\SpaceMember;
use App\Observers\DocumentObserver;
use App\Policies\SpacePolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Documents (05-API-Specification "Documents"; F1/F2/F3). */
final class DocumentController extends Controller
{
    /** GET /v1/documents?space_id=&folder_id=&state=&owner_id=&q= */
    public function index(Request $request): JsonResponse
    {
        $f = $request->validate([
            'space_id' => ['nullable', 'string', 'size:26'],
            'folder_id' => ['nullable', 'string', 'size:26'],
            'state' => ['nullable', Rule::in(Document::STATES)],
            'owner_id' => ['nullable', 'string', 'size:26'],
            'doc_type' => ['nullable', Rule::in(Document::TYPES)],
            'q' => ['nullable', 'string', 'max:200'],
            'limit' => ['nullable', 'integer', 'between:1,100'],
        ]);
        $user = $request->user();
        $isAdmin = $request->attributes->get('workspace_role') === 'admin';

        $q = Document::query()->with('owner:id,name');
        // Space visibility: admins see all; others only their spaces (A5).
        if (! $isAdmin) {
            $q->whereIn('space_id', SpaceMember::query()->where('user_id', $user->id)->pluck('space_id'));
        }
        // Drafts appear only to their author/owner or space approvers (S6 rules). Archived never for readers.
        $approverSpaces = SpaceMember::query()->where('user_id', $user->id)->whereIn('role', ['admin', 'approver'])->pluck('space_id');
        $editorSpaces = SpaceMember::query()->where('user_id', $user->id)->whereIn('role', ['admin', 'approver', 'editor'])->pluck('space_id');
        if (! $isAdmin) {
            $q->where(function ($w) use ($user, $approverSpaces, $editorSpaces): void {
                $w->whereIn('state', ['approved', 'in_review'])
                    ->orWhere(fn ($d) => $d->where('state', 'draft')->where(fn ($x) => $x->where('created_by', $user->id)->orWhere('owner_id', $user->id)->orWhereIn('space_id', $approverSpaces)))
                    ->orWhere(fn ($d) => $d->where('state', 'archived')->whereIn('space_id', $editorSpaces));
            });
        }
        foreach (['space_id', 'folder_id', 'state', 'owner_id', 'doc_type'] as $k) {
            if (! empty($f[$k])) {
                $q->where($k, $f[$k]);
            }
        }
        if (! empty($f['q'])) {
            $term = '%'.str_replace(['%', '_'], ['\%', '\_'], $f['q']).'%';
            $q->where(fn ($w) => $w->where('title', 'like', $term)->orWhere('body_text', 'like', $term));
        }
        $rows = $q->orderByDesc('updated_at')->limit($f['limit'] ?? 50)->get();

        return response()->json(['data' => $rows->map(fn (Document $d) => $this->summary($d))]);
    }

    /** GET /v1/templates */
    public function templates(): JsonResponse
    {
        return response()->json(['data' => array_map(fn ($t) => ['id' => $t['id'], 'name' => $t['name'], 'doc_type' => $t['doc_type'], 'description' => $t['description']], Templates::all())]);
    }

    /** POST /v1/documents  body { space_id, folder_id?, title, doc_type?, template_id?, owner_id? } */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'space_id' => ['required', 'string', 'size:26'],
            'folder_id' => ['nullable', 'string', 'size:26'],
            'title' => ['required', 'string', 'min:1', 'max:250'],
            'doc_type' => ['nullable', Rule::in(Document::TYPES)],
            'template_id' => ['nullable', 'string', 'max:40'],
            'owner_id' => ['nullable', 'string', 'size:26'],
        ]);
        $space = Space::query()->findOrFail($data['space_id']);
        $this->authorize('create', [Document::class, $space]);
        if (! empty($data['folder_id'])) {
            Folder::query()->where('space_id', $space->id)->findOrFail($data['folder_id']);
        }

        $template = ! empty($data['template_id']) ? Templates::find($data['template_id']) : null;
        abort_if(! empty($data['template_id']) && $template === null, 422, 'Unknown template.');

        $doc = DB::transaction(function () use ($data, $space, $template, $request): Document {
            $doc = Document::create([
                'space_id' => $space->id,
                'folder_id' => $data['folder_id'] ?? null,
                'title' => $data['title'],
                'doc_type' => $data['doc_type'] ?? ($template['doc_type'] ?? 'sop'),
                'owner_id' => $data['owner_id'] ?? $request->user()->id,
                'created_by' => $request->user()->id,
                'content' => $template['content'] ?? Content::empty(),
            ]);
            foreach ($template['steps'] ?? [] as $i => $s) {
                DocumentStep::create($s + ['document_id' => $doc->id, 'position' => $i + 1]);
            }
            if (! empty($template['steps'])) {
                $doc->body_text = DocumentObserver::flatten($doc->load('steps'));
                $doc->save();
            }

            return $doc->refresh(); // pick up DB defaults (state, language)
        });
        Audit::record('document.created', 'document', $doc->id, ['title' => $doc->title, 'template' => $data['template_id'] ?? null]);

        return response()->json(['data' => $this->full($doc)], 201);
    }

    /** GET /v1/documents/{id} — working copy + metadata. */
    public function show(string $id): JsonResponse
    {
        $doc = Document::query()->with(['owner:id,name', 'steps'])->findOrFail($id);
        $this->authorize('view', $doc);

        return response()->json(['data' => $this->full($doc)]);
    }

    /** GET /v1/documents/{id}/published — the approved version readers see (FR-407). */
    public function published(string $id): JsonResponse
    {
        $doc = Document::query()->with(['owner:id,name', 'approvedVersion'])->findOrFail($id);
        $this->authorize('view', $doc);
        $v = $doc->approvedVersion;
        if ($v === null) {
            return response()->json(['error' => ['code' => 'not_published', 'message' => 'This document has no approved version yet.']], 409);
        }

        return response()->json(['data' => [
            'id' => $doc->id, 'title' => $v->title, 'doc_type' => $doc->doc_type, 'state' => $doc->state,
            'version_number' => $v->version_number, 'approved_at' => $v->approved_at, 'approved_by' => $v->approved_by,
            'owner' => $doc->owner?->only(['id', 'name']), 'review_due_at' => $doc->review_due_at,
            'content' => $v->content,
            'steps' => $v->steps()->get()->map(fn (DocumentStep $s) => $this->step($s)),
        ]]);
    }

    /**
     * PATCH /v1/documents/{id} — title, content (partial), owner, folder, requires_ack, language.
     * expected_updated_at gives optimistic concurrency: mismatch → 409 with the current state (FR-210).
     * Editing an approved document flips it to a draft revision without unpublishing (FR-408).
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $doc = Document::query()->findOrFail($id);
        $this->authorize('edit', $doc);
        $data = $request->validate([
            'title' => ['sometimes', 'string', 'min:1', 'max:250'],
            'owner_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'folder_id' => ['sometimes', 'nullable', 'string', 'size:26'],
            'requires_ack' => ['sometimes', 'boolean'],
            'language' => ['sometimes', 'string', 'max:10'],
            'review_due_at' => ['sometimes', 'nullable', 'date'],
            'expected_updated_at' => ['sometimes', 'nullable', 'date'],
        ] + Content::rules('content'));

        if (! empty($data['expected_updated_at']) && ! Carbon::parse($data['expected_updated_at'])->equalTo($doc->updated_at)) {
            return response()->json(['error' => [
                'code' => 'conflict',
                'message' => 'This document changed while you were editing. Reload to see the current version.',
                'details' => ['current' => $this->full($doc->load('steps'))],
            ]], 409);
        }
        if (array_key_exists('folder_id', $data) && $data['folder_id'] !== null) {
            Folder::query()->where('space_id', $doc->space_id)->findOrFail($data['folder_id']);
        }
        if (array_key_exists('content', $data)) {
            $data['content'] = Content::merge($doc->content ?? [], $data['content'] ?? []);
        }
        unset($data['expected_updated_at']);

        $contentChanged = isset($data['content']) || isset($data['title']);
        if ($doc->state === 'approved' && $contentChanged) {
            $data['state'] = 'draft';   // new draft revision; approved_version_id stays → still published
            Audit::record('document.revision_started', 'document', $doc->id, ['from_version' => $doc->approved_version_id]);
        }
        $doc->fill($data)->save();

        return response()->json(['data' => $this->full($doc->load('steps'))]);
    }

    /** DELETE /v1/documents/{id} — soft delete; versions retained. */
    public function destroy(string $id): JsonResponse
    {
        $doc = Document::query()->findOrFail($id);
        $this->authorize('delete', $doc);
        $doc->delete();
        Audit::record('document.deleted', 'document', $doc->id, ['title' => $doc->title]);

        return response()->json(['data' => ['id' => $id, 'deleted' => true]]);
    }

    /** POST /v1/documents/{id}/duplicate — copy the working copy as a new draft. */
    public function duplicate(Request $request, string $id): JsonResponse
    {
        $src = Document::query()->with('steps')->findOrFail($id);
        $this->authorize('view', $src);
        $this->authorize('create', [Document::class, Space::query()->findOrFail($src->space_id)]);

        $copy = DB::transaction(function () use ($src, $request): Document {
            $copy = Document::create([
                'space_id' => $src->space_id, 'folder_id' => $src->folder_id, 'title' => $src->title.' (copy)',
                'doc_type' => $src->doc_type, 'owner_id' => $request->user()->id, 'created_by' => $request->user()->id,
                'content' => $src->content, 'language' => $src->language,
            ]);
            foreach ($src->steps as $s) {
                DocumentStep::create($s->only(['position', 'instruction', 'note', 'expected_result', 'is_critical', 'is_checkpoint', 'media_asset_id']) + ['document_id' => $copy->id]);
            }
            $copy->body_text = DocumentObserver::flatten($copy->load('steps'));
            $copy->save();

            return $copy->refresh();
        });

        return response()->json(['data' => $this->full($copy)], 201);
    }

    /** POST /v1/documents/{id}/move  body { space_id?, folder_id? } */
    public function move(Request $request, string $id): JsonResponse
    {
        $doc = Document::query()->findOrFail($id);
        $this->authorize('edit', $doc);
        $data = $request->validate([
            'space_id' => ['sometimes', 'string', 'size:26'],
            'folder_id' => ['sometimes', 'nullable', 'string', 'size:26'],
        ]);
        $targetSpaceId = $data['space_id'] ?? $doc->space_id;
        if ($targetSpaceId !== $doc->space_id) {
            $target = Space::query()->findOrFail($targetSpaceId);
            abort_unless(app(SpacePolicy::class)->edit($request->user(), $target), 403, 'Not permitted.');
        }
        $folderId = array_key_exists('folder_id', $data) ? $data['folder_id'] : ($targetSpaceId === $doc->space_id ? $doc->folder_id : null);
        if ($folderId !== null) {
            Folder::query()->where('space_id', $targetSpaceId)->findOrFail($folderId);
        }
        $doc->fill(['space_id' => $targetSpaceId, 'folder_id' => $folderId])->save();
        Audit::record('document.moved', 'document', $doc->id, ['space_id' => $targetSpaceId, 'folder_id' => $folderId]);
        // Retrieval chunks re-scoped on move once M3 lands (FR-615).

        return response()->json(['data' => $this->summary($doc)]);
    }

    /** @return array<string, mixed> */
    private function summary(Document $d): array
    {
        return [
            'id' => $d->id, 'space_id' => $d->space_id, 'folder_id' => $d->folder_id, 'title' => $d->title,
            'doc_type' => $d->doc_type, 'state' => $d->state, 'owner' => $d->owner?->only(['id', 'name']),
            'approved_version_id' => $d->approved_version_id, 'review_due_at' => $d->review_due_at,
            'requires_ack' => $d->requires_ack, 'language' => $d->language, 'updated_at' => $d->updated_at,
        ];
    }

    /** @return array<string, mixed> */
    private function full(Document $d): array
    {
        $steps = $d->relationLoaded('steps') ? $d->steps : $d->steps()->get();

        return $this->summary($d) + [
            'content' => $d->content,
            'steps' => $steps->map(fn (DocumentStep $s) => $this->step($s))->values(),
            'created_by' => $d->created_by, 'source_recording_id' => $d->source_recording_id,
            'created_at' => $d->created_at,
        ];
    }

    /** @return array<string, mixed> */
    private function step(DocumentStep $s): array
    {
        return $s->only(['id', 'position', 'instruction', 'note', 'expected_result', 'is_critical', 'is_checkpoint', 'media_asset_id', 'source_ts_start', 'source_ts_end', 'verified_at']);
    }
}
