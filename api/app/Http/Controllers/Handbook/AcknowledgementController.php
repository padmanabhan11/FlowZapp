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
use App\Models\User;
use App\Notifications\AcknowledgementDueNotification;
use App\Policies\DocumentPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * S17 — acknowledgement tracking (FR-702..707, BR-24..26, BRL-07).
 * "HR" in the spec is an approver on the handbook space; admins see everything.
 * An acknowledgement always binds to the current approved version, so a new
 * approval leaves every target outstanding again (FR-704) without any reset.
 */
final class AcknowledgementController extends Controller
{
    public function __construct(private readonly DocumentPolicy $policy) {}

    /** POST /v1/documents/{id}/acknowledgement-targets  body { user_ids: [] } — replaces the set. */
    public function targets(Request $request, string $id): JsonResponse
    {
        $doc = Document::query()->findOrFail($id);
        $this->assertTracker($request->user(), $doc);
        $data = $request->validate(['user_ids' => ['present', 'array', 'max:1000'], 'user_ids.*' => ['string', 'size:26']]);

        // Only workspace members can be targets; silently drop anyone else (never reveal membership by error).
        $members = User::query()->whereIn('id', $data['user_ids'])
            ->whereHas('workspaces', fn ($q) => $q->whereKey($doc->workspace_id))->pluck('id')->all();

        DB::transaction(function () use ($doc, $members, $request): void {
            AcknowledgementTarget::query()->where('document_id', $doc->id)->whereNotIn('user_id', $members)->delete();
            $existing = AcknowledgementTarget::query()->where('document_id', $doc->id)->pluck('user_id')->all();
            foreach (array_diff($members, $existing) as $uid) {
                AcknowledgementTarget::create(['document_id' => $doc->id, 'user_id' => $uid, 'assigned_by' => $request->user()->id]);
            }
            $doc->forceFill(['requires_ack' => $members !== []])->save();
        });
        Audit::record('acknowledgement.targets_set', 'document', $doc->id, ['count' => count($members)]);

        // Newly assigned people with an approved version to read get told now (FR-702).
        if ($doc->approved_version_id) {
            $new = array_diff($members, Acknowledgement::query()->where('version_id', $doc->approved_version_id)->pluck('user_id')->all());
            foreach (User::query()->whereIn('id', $new)->get() as $u) {
                $u->notify(new AcknowledgementDueNotification($doc->title, (int) $doc->approvedVersion?->version_number, $doc->id));
            }
        }

        return response()->json(['data' => ['document_id' => $doc->id, 'user_ids' => $members, 'requires_ack' => $members !== []]]);
    }

    /** POST /v1/documents/{id}/acknowledge — caller confirms the current approved version. Idempotent. */
    public function acknowledge(Request $request, string $id): JsonResponse
    {
        $doc = Document::query()->with('approvedVersion')->findOrFail($id);
        $this->authorize('view', $doc);
        $user = $request->user();
        abort_unless(AcknowledgementTarget::query()->where('document_id', $doc->id)->where('user_id', $user->id)->exists(), 403, 'You are not assigned to acknowledge this document.');
        abort_if($doc->approved_version_id === null || $doc->state === 'archived', 409, 'There is no approved version to acknowledge.');

        $ack = Acknowledgement::query()->where('version_id', $doc->approved_version_id)->where('user_id', $user->id)->first()
            ?? Acknowledgement::create(['document_id' => $doc->id, 'version_id' => $doc->approved_version_id, 'user_id' => $user->id, 'acknowledged_at' => now()]);
        if ($ack->wasRecentlyCreated) {
            Audit::record('document.acknowledged', 'document', $doc->id, ['version' => $doc->approvedVersion?->version_number]);
        }

        return response()->json(['data' => [
            'document_id' => $doc->id, 'version_id' => $ack->version_id, 'version_number' => $doc->approvedVersion?->version_number,
            'user' => $user->only(['id', 'name']), 'acknowledged_at' => $ack->acknowledged_at,
        ]]);
    }

    /**
     * GET /v1/acknowledgements?document_id=&user_id=&status=outstanding|done — one row per
     * target per current approved version (FR-705). Scoped to spaces the caller can track.
     */
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'document_id' => ['nullable', 'string', 'size:26'], 'user_id' => ['nullable', 'string', 'size:26'],
            'status' => ['nullable', Rule::in(['outstanding', 'done'])], 'space_id' => ['nullable', 'string', 'size:26'],
        ]);

        return response()->json(['data' => $this->rows($request->user(), $data)->values()]);
    }

    /** GET /v1/acknowledgements/export?space_id= — CSV, one row per user per document version (FR-707). */
    public function export(Request $request): StreamedResponse
    {
        $data = $request->validate(['space_id' => ['nullable', 'string', 'size:26']]);
        $rows = $this->rows($request->user(), $data);
        Audit::record('acknowledgement.exported', 'workspace', app(\App\Tenancy\CurrentWorkspace::class)->require(), ['rows' => $rows->count()]);

        return response()->streamDownload(function () use ($rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['user_name', 'user_email', 'document_id', 'document_title', 'version_number', 'status', 'acknowledged_at']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['user']['name'], $r['user']['email'], $r['document_id'], $r['title'], $r['version_number'], $r['status'], $r['acknowledged_at']?->toIso8601String()]);
            }
            fclose($out);
        }, 'acknowledgements-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** POST /v1/acknowledgements/remind  body { document_id, user_ids? } — nudge outstanding people (FR-706). */
    public function remind(Request $request): JsonResponse
    {
        $data = $request->validate(['document_id' => ['required', 'string', 'size:26'], 'user_ids' => ['nullable', 'array'], 'user_ids.*' => ['string', 'size:26']]);
        $doc = Document::query()->with('approvedVersion')->findOrFail($data['document_id']);
        $this->assertTracker($request->user(), $doc);
        abort_if($doc->approved_version_id === null, 409, 'Nothing to acknowledge yet.');

        $outstanding = $this->rows($request->user(), ['document_id' => $doc->id, 'status' => 'outstanding'])->pluck('user.id')->all();
        if (! empty($data['user_ids'])) {
            $outstanding = array_values(array_intersect($outstanding, $data['user_ids']));
        }
        foreach (User::query()->whereIn('id', $outstanding)->get() as $u) {
            $u->notify(new AcknowledgementDueNotification($doc->title, (int) $doc->approvedVersion?->version_number, $doc->id, reminder: true));
        }
        Audit::record('acknowledgement.reminded', 'document', $doc->id, ['count' => count($outstanding)]);

        return response()->json(['data' => ['document_id' => $doc->id, 'reminded' => count($outstanding)]]);
    }

    /**
     * @param  array<string,mixed>  $f
     * @return Collection<int, array<string,mixed>>
     */
    private function rows(User $user, array $f): Collection
    {
        $isAdmin = request()->attributes->get('workspace_role') === 'admin';
        $spaceIds = $isAdmin
            ? Space::query()->pluck('id')->all()
            : Space::query()->get()->filter(fn (Space $s) => Access::atLeast(Access::resolve($user, $s)['role'], 'approver'))->pluck('id')->all();
        if (! empty($f['space_id'])) {
            $spaceIds = array_values(array_intersect($spaceIds, [$f['space_id']]));
        }

        $docs = Document::query()->with('approvedVersion:id,version_number')->whereIn('space_id', $spaceIds)->where('requires_ack', true)
            ->when(! empty($f['document_id']), fn ($q) => $q->whereKey($f['document_id']))
            ->whereNotNull('approved_version_id')->get()->keyBy('id');
        if ($docs->isEmpty()) {
            return collect();
        }
        $targets = AcknowledgementTarget::query()->with('user:id,name,email')->whereIn('document_id', $docs->keys())
            ->when(! empty($f['user_id']), fn ($q) => $q->where('user_id', $f['user_id']))->get();
        $acks = Acknowledgement::query()->whereIn('version_id', $docs->pluck('approved_version_id'))->get()
            ->keyBy(fn (Acknowledgement $a) => $a->version_id.':'.$a->user_id);

        return $targets->map(function (AcknowledgementTarget $t) use ($docs, $acks): array {
            $d = $docs[$t->document_id];
            $ack = $acks->get($d->approved_version_id.':'.$t->user_id);

            return [
                'document_id' => $d->id, 'title' => $d->title, 'space_id' => $d->space_id,
                'version_id' => $d->approved_version_id, 'version_number' => $d->approvedVersion?->version_number,
                'user' => $t->user?->only(['id', 'name', 'email']),
                'status' => $ack ? 'done' : 'outstanding', 'acknowledged_at' => $ack?->acknowledged_at, 'assigned_at' => $t->created_at,
            ];
        })->when(! empty($f['status']), fn ($c) => $c->filter(fn ($r) => $r['status'] === $f['status']));
    }

    /** Admin, or approver on the document's space ("HR" in S17). */
    private function assertTracker(User $user, Document $doc): void
    {
        $isAdmin = request()->attributes->get('workspace_role') === 'admin';
        abort_unless($isAdmin || Access::atLeast($this->policy->roleFor($user, $doc), 'approver'), 403, 'Not permitted.');
    }
}
