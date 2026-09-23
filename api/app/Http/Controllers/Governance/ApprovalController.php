<?php

declare(strict_types=1);

namespace App\Http\Controllers\Governance;

use App\Governance\Diff;
use App\Governance\Snapshot;
use App\Governance\Workflow;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\Document;
use App\Models\DocumentStep;
use App\Models\DocumentVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Approval workflow and versions (05 "Approval workflow", "Versions"; F5, F6). */
final class ApprovalController extends Controller
{
    public function __construct(private readonly Workflow $workflow) {}

    /** POST /v1/documents/{id}/submit  body { reviewer_id? } */
    public function submit(Request $request, string $id): JsonResponse
    {
        $doc = Document::query()->findOrFail($id);
        $this->authorize('edit', $doc);
        $data = $request->validate(['reviewer_id' => ['nullable', 'string', 'size:26']]);
        $doc = $this->workflow->submit($doc, $request->user(), $data['reviewer_id'] ?? null);

        return response()->json(['data' => ['id' => $doc->id, 'state' => $doc->state, 'submitted_at' => $doc->submitted_at]]);
    }

    /** GET /v1/documents/{id}/submit-check — what still blocks submission (S8/S9 button state). */
    public function submitCheck(string $id): JsonResponse
    {
        $doc = Document::query()->findOrFail($id);
        $this->authorize('view', $doc);

        return response()->json(['data' => ['blockers' => $this->workflow->submitBlockers($doc)]]);
    }

    /** POST /v1/documents/{id}/approve  body { change_summary?, expected_updated_at? } */
    public function approve(Request $request, string $id): JsonResponse
    {
        $doc = Document::query()->findOrFail($id);
        $this->authorize('archive', $doc); // approve permission == space approver/admin
        $data = $request->validate(['change_summary' => ['nullable', 'string', 'max:500'], 'expected_updated_at' => ['nullable', 'date']]);
        $version = $this->workflow->approve($doc, $request->user(), $data['change_summary'] ?? null, $data['expected_updated_at'] ?? null);

        return response()->json(['data' => ['id' => $doc->id, 'state' => 'approved', 'version_id' => $version->id, 'version_number' => $version->version_number, 'approved_at' => $version->approved_at]]);
    }

    /** POST /v1/documents/{id}/request-changes  body { comment } */
    public function requestChanges(Request $request, string $id): JsonResponse
    {
        $doc = Document::query()->findOrFail($id);
        $this->authorize('archive', $doc);
        $data = $request->validate(['comment' => ['required', 'string', 'min:3', 'max:5000']]);
        $doc = $this->workflow->requestChanges($doc, $request->user(), $data['comment']);

        return response()->json(['data' => ['id' => $doc->id, 'state' => $doc->state]]);
    }

    /** POST /v1/documents/{id}/archive */
    public function archive(Request $request, string $id): JsonResponse
    {
        $doc = Document::query()->findOrFail($id);
        $this->authorize('archive', $doc);
        $doc = $this->workflow->archive($doc, $request->user());

        return response()->json(['data' => ['id' => $doc->id, 'state' => $doc->state]]);
    }

    /** GET /v1/documents/{id}/approvals — transition history. */
    public function history(string $id): JsonResponse
    {
        $doc = Document::query()->findOrFail($id);
        $this->authorize('view', $doc);
        $rows = Approval::query()->where('document_id', $doc->id)->with(['reviewer:id,name', 'requester:id,name'])->orderBy('created_at')->get();

        return response()->json(['data' => $rows->map(fn (Approval $a) => [
            'id' => $a->id, 'from' => $a->from_state, 'to' => $a->to_state, 'comment' => $a->comment,
            'requested_by' => $a->requester?->only(['id', 'name']), 'reviewer' => $a->reviewer?->only(['id', 'name']), 'at' => $a->created_at,
        ])]);
    }

    /** GET /v1/documents/{id}/versions */
    public function versions(string $id): JsonResponse
    {
        $doc = Document::query()->findOrFail($id);
        $this->authorize('view', $doc);
        $rows = DocumentVersion::query()->where('document_id', $doc->id)->orderByDesc('version_number')->get();

        return response()->json(['data' => $rows->map(fn (DocumentVersion $v) => $this->versionMeta($v, $doc))]);
    }

    /** GET /v1/documents/{id}/versions/{version_id} — full content of a version. */
    public function version(string $id, string $versionId): JsonResponse
    {
        $doc = Document::query()->findOrFail($id);
        $this->authorize('view', $doc);
        $v = DocumentVersion::query()->where('document_id', $doc->id)->findOrFail($versionId);
        $content = $v->content ?? [];
        unset($content['_steps']);

        return response()->json(['data' => $this->versionMeta($v, $doc) + [
            'content' => $content,
            'steps' => $v->steps()->get()->map(fn (DocumentStep $s) => Snapshot::step($s, $s->id)),
        ]]);
    }

    /** GET /v1/documents/{id}/diff?from=&to=  (version ids, or "working" for the working copy). */
    public function diff(Request $request, string $id): JsonResponse
    {
        $doc = Document::query()->findOrFail($id);
        $this->authorize('view', $doc);
        $data = $request->validate(['from' => ['required', 'string', 'max:26'], 'to' => ['required', 'string', 'max:26']]);
        $snap = fn (string $ref) => $ref === 'working'
            ? Snapshot::ofWorkingCopy($doc)
            : Snapshot::ofVersion(DocumentVersion::query()->where('document_id', $doc->id)->findOrFail($ref));

        return response()->json(['data' => ['from' => $data['from'], 'to' => $data['to'], 'changes' => Diff::compute($snap($data['from']), $snap($data['to']))]]);
    }

    /** GET /v1/documents/{id}/review — what an approver needs (S12): diff vs the last approved version, or full content when first. */
    public function review(string $id): JsonResponse
    {
        $doc = Document::query()->with(['owner:id,name', 'steps'])->findOrFail($id);
        $this->authorize('view', $doc);
        $base = $doc->approved_version_id ? DocumentVersion::query()->find($doc->approved_version_id) : null;
        $changes = $base ? Diff::compute(Snapshot::ofVersion($base), Snapshot::ofWorkingCopy($doc)) : null;

        return response()->json(['data' => [
            'id' => $doc->id, 'title' => $doc->title, 'state' => $doc->state, 'doc_type' => $doc->doc_type, 'updated_at' => $doc->updated_at,
            'submitted_by' => $doc->submitted_by, 'submitted_at' => $doc->submitted_at, 'owner' => $doc->owner?->only(['id', 'name']),
            'base_version' => $base ? $this->versionMeta($base, $doc) : null,
            'next_version_number' => (int) DocumentVersion::query()->where('document_id', $doc->id)->max('version_number') + 1,
            'changes' => $changes,
            'content' => $doc->content, 'steps' => $doc->steps->map(fn (DocumentStep $s) => Snapshot::step($s, $s->id)),
            'blockers' => $this->workflow->submitBlockers($doc),
        ]]);
    }

    /** POST /v1/documents/{id}/versions/{version_id}/restore — new draft; live version unaffected. */
    public function restore(Request $request, string $id, string $versionId): JsonResponse
    {
        $doc = Document::query()->findOrFail($id);
        $this->authorize('edit', $doc);
        $v = DocumentVersion::query()->where('document_id', $doc->id)->findOrFail($versionId);
        $doc = $this->workflow->restore($doc, $v, $request->user());

        return response()->json(['data' => ['id' => $doc->id, 'state' => $doc->state, 'title' => $doc->title, 'restored_from' => $v->version_number, 'approved_version_id' => $doc->approved_version_id]]);
    }

    /**
     * @return array<string,mixed>
     */
    private function versionMeta(DocumentVersion $v, Document $doc): array
    {
        return [
            'id' => $v->id, 'version_number' => $v->version_number, 'title' => $v->title, 'change_summary' => $v->change_summary,
            'authored_by' => $v->authored_by, 'approved_by' => $v->approved_by, 'approved_at' => $v->approved_at, 'created_at' => $v->created_at,
            'is_live' => $doc->approved_version_id === $v->id,
        ];
    }
}
