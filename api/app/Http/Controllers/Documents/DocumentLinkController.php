<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * B7 — internal links. A link target the caller cannot see is reported exactly
 * like one that no longer exists: absent (non-negotiable 3). The UI renders
 * both as "unavailable", which is also how a broken link is shown.
 */
final class DocumentLinkController extends Controller
{
    /** POST /v1/document-links/resolve  body { ids: [] } → the subset the caller can open, with title and state. */
    public function resolve(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:200'], 'ids.*' => ['string', 'size:26']]);
        $user = $request->user();
        $docs = Document::query()->whereIn('id', array_unique($data['ids']))->get(['id', 'space_id', 'folder_id', 'title', 'state', 'doc_type', 'created_by', 'owner_id', 'approved_version_id'])
            ->filter(fn (Document $d) => $user->can('view', $d));

        return response()->json(['data' => $docs->map(fn (Document $d) => [
            'id' => $d->id, 'title' => $d->title, 'state' => $d->state, 'doc_type' => $d->doc_type, 'published' => $d->approved_version_id !== null,
        ])->values()]);
    }

    /** GET /v1/documents/{id}/backlinks — documents that link here and the caller can see. */
    public function backlinks(Request $request, string $id): JsonResponse
    {
        $target = Document::query()->findOrFail($id);
        $this->authorize('view', $target);
        $user = $request->user();
        $sourceIds = DocumentLink::query()->where('target_document_id', $target->id)->pluck('source_document_id')->unique();
        $sources = Document::query()->whereIn('id', $sourceIds)->orderBy('title')->get()->filter(fn (Document $d) => $user->can('view', $d));

        return response()->json(['data' => $sources->map(fn (Document $d) => ['id' => $d->id, 'title' => $d->title, 'state' => $d->state, 'doc_type' => $d->doc_type])->values()]);
    }
}
