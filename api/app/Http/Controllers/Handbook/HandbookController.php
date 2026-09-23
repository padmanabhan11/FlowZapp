<?php

declare(strict_types=1);

namespace App\Http\Controllers\Handbook;

use App\Access\Access;
use App\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\Acknowledgement;
use App\Models\AcknowledgementTarget;
use App\Models\Document;
use App\Models\Space;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * S16 — the handbook: a designated space read in an order the space owner
 * sets (FR-701, BR-23). Only approved documents appear; the same visibility
 * rule as everywhere else (nothing the caller cannot access is listed).
 */
final class HandbookController extends Controller
{
    /** GET /v1/handbook?space_id= — defaults to the workspace's handbook space. */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate(['space_id' => ['nullable', 'string', 'size:26']]);
        $space = isset($data['space_id'])
            ? Space::query()->findOrFail($data['space_id'])
            : Space::query()->where('is_handbook', true)->first();
        if ($space === null) {
            return response()->json(['data' => ['space' => null, 'documents' => []]]);
        }
        $this->authorize('view', $space);
        $user = $request->user();
        $role = Access::resolve($user, $space)['role'];

        $docs = Document::query()->with(['approvedVersion:id,version_number,title,approved_at'])
            ->where('space_id', $space->id)->live()   // a page being revised stays in the handbook at its approved version
            ->orderByRaw('handbook_position is null, handbook_position asc') // allowlisted: null-last ordering; no user input
            ->orderBy('title')->get()
            ->filter(fn (Document $d) => $user->can('view', $d))->values();

        $targets = AcknowledgementTarget::query()->where('user_id', $user->id)->whereIn('document_id', $docs->pluck('id'))->pluck('document_id')->all();
        $acks = Acknowledgement::query()->where('user_id', $user->id)->whereIn('version_id', $docs->pluck('approved_version_id'))->get()->keyBy('version_id');

        return response()->json(['data' => [
            'space' => $space->only(['id', 'name', 'description', 'is_handbook']),
            'can_reorder' => Access::atLeast($role, 'approver'),
            'documents' => $docs->map(fn (Document $d) => [
                'id' => $d->id, 'title' => $d->approvedVersion->title ?? $d->title, 'doc_type' => $d->doc_type, 'handbook_position' => $d->handbook_position,
                'version_number' => $d->approvedVersion?->version_number, 'approved_at' => $d->approvedVersion?->approved_at,
                'requires_ack' => $d->requires_ack,
                'ack_required_from_me' => in_array($d->id, $targets, true),
                'acknowledged_at' => $acks->get($d->approved_version_id)?->acknowledged_at,
            ]),
        ]]);
    }

    /** PUT /v1/handbook/order  body { space_id, order: [document ids] } — space approver/admin (the "space owner" of S16). */
    public function reorder(Request $request): JsonResponse
    {
        $data = $request->validate(['space_id' => ['required', 'string', 'size:26'], 'order' => ['required', 'array', 'max:500'], 'order.*' => ['string', 'size:26']]);
        $space = Space::query()->findOrFail($data['space_id']);
        abort_unless(Access::atLeast(Access::resolve($request->user(), $space)['role'], 'approver'), 403, 'Not permitted.');

        DB::transaction(function () use ($data, $space): void {
            foreach (array_values($data['order']) as $i => $id) {
                Document::query()->where('space_id', $space->id)->whereKey($id)->update(['handbook_position' => $i + 1]);
            }
        });
        Audit::record('handbook.reordered', 'space', $space->id, ['count' => count($data['order'])]);

        return $this->index($request->merge(['space_id' => $space->id]));
    }
}
