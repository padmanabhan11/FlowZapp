<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Models\Acknowledgement;
use App\Models\AcknowledgementTarget;
use App\Models\Document;
use App\Models\Space;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * L2-T2 owner dashboard: GET /v1/me/documents — the documents the caller
 * owns, with what each one needs from them: a review that is overdue or due
 * soon, a draft waiting to be submitted, a review in progress, a translation
 * gone stale, acknowledgements still outstanding. Sorted most urgent first.
 */
final class MyDocumentsController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $docs = Document::query()->where('owner_id', $user->id)->where('state', '!=', 'archived')
            ->with(['approvedVersion:id,version_number,approved_at'])->get();
        $spaces = Space::query()->whereIn('id', $docs->pluck('space_id')->unique())->get(['id', 'name'])->keyBy('id');

        $ackDocs = $docs->where('requires_ack', true)->whereNotNull('approved_version_id');
        $targets = AcknowledgementTarget::query()->whereIn('document_id', $ackDocs->pluck('id'))->get(['document_id', 'user_id'])->groupBy('document_id');
        $acks = Acknowledgement::query()->whereIn('version_id', $ackDocs->pluck('approved_version_id'))->get(['version_id', 'user_id'])->groupBy('version_id');

        $soon = now()->addDays(30);
        $rows = $docs->map(function (Document $d) use ($spaces, $targets, $acks, $soon): array {
            $needs = [];
            if ($d->review_due_at !== null && $d->approved_version_id !== null) {
                if ($d->review_due_at->isPast()) {
                    $needs[] = 'review_overdue';
                } elseif ($d->review_due_at->lte($soon)) {
                    $needs[] = 'review_soon';
                }
            }
            if ($d->state === 'draft') {
                $needs[] = $d->approved_version_id ? 'revision_in_draft' : 'draft';
            }
            if ($d->state === 'in_review') {
                $needs[] = 'in_review';
            }
            if ($d->translation_stale) {
                $needs[] = 'translation_stale';
            }
            $outstanding = 0;
            if ($d->requires_ack && $d->approved_version_id) {
                $want = $targets->get($d->id, collect())->pluck('user_id')->unique();
                $have = $acks->get($d->approved_version_id, collect())->pluck('user_id')->unique();
                $outstanding = $want->diff($have)->count();
                if ($outstanding > 0) {
                    $needs[] = 'acknowledgements_outstanding';
                }
            }

            return [
                'id' => $d->id, 'title' => $d->title, 'doc_type' => $d->doc_type, 'state' => $d->state,
                'space' => $spaces->get($d->space_id)?->only(['id', 'name']),
                'review_due_at' => $d->review_due_at, 'approved_at' => $d->approvedVersion?->approved_at, 'version_number' => $d->approvedVersion?->version_number,
                'translation_stale' => $d->translation_stale, 'acknowledgements_outstanding' => $outstanding,
                'needs' => $needs, 'updated_at' => $d->updated_at,
            ];
        });

        $rank = ['review_overdue' => 0, 'acknowledgements_outstanding' => 1, 'translation_stale' => 2, 'in_review' => 3, 'revision_in_draft' => 4, 'draft' => 5, 'review_soon' => 6];
        $rows = $rows->sortBy(fn (array $r) => [$r['needs'] === [] ? 99 : min(array_map(fn ($n) => $rank[$n], $r['needs'])), -($r['updated_at']?->getTimestamp() ?? 0)])->values();

        return response()->json(['data' => [
            'documents' => $rows,
            'summary' => [
                'total' => $rows->count(),
                'needing_attention' => $rows->filter(fn ($r) => $r['needs'] !== [])->count(),
                'review_overdue' => $rows->filter(fn ($r) => in_array('review_overdue', $r['needs'], true))->count(),
                'review_soon' => $rows->filter(fn ($r) => in_array('review_soon', $r['needs'], true))->count(),
            ],
        ]]);
    }
}
