<?php

declare(strict_types=1);

namespace App\Http\Controllers\Recordings;

use App\Audit\Audit;
use App\Billing\PlanLimits;
use App\Http\Controllers\Controller;
use App\Jobs\Pipeline\TranscribeRecording;
use App\Media\MediaStorage;
use App\Models\Recording;
use App\Models\Space;
use App\Models\Workspace;
use App\Policies\SpacePolicy;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** Recordings & generation (05 "Recordings & generation"; F8; S10/S11). */
final class RecordingController extends Controller
{
    public function __construct(private readonly MediaStorage $storage, private readonly CurrentWorkspace $current) {}

    /**
     * POST /v1/recordings/upload-url  body { filename, mime_type, size_bytes, space_id, title? }
     * Creates the recording row (pending_upload) and returns presigned multipart targets.
     * Limits (60 min / 2 GB) and plan minutes are checked here, before any bytes move.
     */
    public function uploadUrl(Request $request): JsonResponse
    {
        $this->authorize('create', Recording::class);
        $data = $request->validate([
            'filename' => ['required', 'string', 'max:250'],
            'mime_type' => ['required', Rule::in(Recording::MIME_TYPES)],
            'size_bytes' => ['required', 'integer', 'min:1', 'max:'.Recording::MAX_BYTES],
            'duration_sec' => ['nullable', 'numeric', 'min:0', 'max:'.Recording::MAX_SECONDS],
            'space_id' => ['required', 'string', 'size:26'],
            'title' => ['nullable', 'string', 'max:250'],
        ]);
        $space = Space::query()->findOrFail($data['space_id']);
        abort_unless(app(SpacePolicy::class)->edit($request->user(), $space), 403, 'Not permitted.');

        if ($limited = $this->minutesLimit($request, (float) ($data['duration_sec'] ?? 0))) {
            return $limited;
        }

        $id = (string) Str::ulid();
        $ext = match ($data['mime_type']) { 'video/mp4' => 'mp4', 'video/quicktime' => 'mov', default => 'webm' };
        $key = "{$this->current->require()}/{$id}/source.{$ext}";
        $targets = $this->storage->createMultipartUpload($key, $data['mime_type'], (int) $data['size_bytes']);

        $rec = new Recording([
            'space_id' => $space->id, 'uploaded_by' => $request->user()->id,
            'title' => $data['title'] ?? pathinfo($data['filename'], PATHINFO_FILENAME) ?: now()->format('j M Y H:i'),
            'storage_key' => $key, 'upload_id' => $targets['upload_id'], 'mime_type' => $data['mime_type'],
            'size_bytes' => (int) $data['size_bytes'], 'duration_sec' => $data['duration_sec'] ?? null, 'state' => 'pending_upload',
        ]);
        $rec->id = $id;
        $rec->save();

        return response()->json(['data' => [
            'recording_id' => $rec->id, 'upload_id' => $targets['upload_id'], 'part_size' => $targets['part_size'], 'parts' => $targets['parts'],
        ]], 201);
    }

    /**
     * POST /v1/recordings  body { recording_id, parts: [{ part_number, etag }], duration_sec? }
     * Completes the multipart upload and starts the pipeline.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'recording_id' => ['required', 'string', 'size:26'],
            'parts' => ['required', 'array', 'min:1'],
            'parts.*.part_number' => ['required', 'integer', 'min:1'],
            'parts.*.etag' => ['required', 'string', 'max:100'],
            'duration_sec' => ['nullable', 'numeric', 'min:0', 'max:'.Recording::MAX_SECONDS],
        ]);
        $rec = Recording::query()->findOrFail($data['recording_id']);
        $this->authorize('manage', $rec);
        abort_unless($rec->state === 'pending_upload', 409, 'This recording has already been registered.');

        $this->storage->completeMultipartUpload($rec->storage_key, (string) $rec->upload_id, $data['parts']);
        $rec->forceFill([
            'upload_id' => null, 'state' => 'uploaded',
            'size_bytes' => $this->storage->size($rec->storage_key) ?? $rec->size_bytes,
            'duration_sec' => $data['duration_sec'] ?? $rec->duration_sec,
        ])->save();
        Audit::record('recording.uploaded', 'recording', $rec->id, ['size_bytes' => $rec->size_bytes]);

        TranscribeRecording::dispatch($rec->workspace_id, $rec->id, (string) $rec->size_bytes);

        return response()->json(['data' => $this->present($rec)], 201);
    }

    /** GET /v1/recordings?state= — own recordings, plus every recording for admins. */
    public function index(Request $request): JsonResponse
    {
        $q = Recording::query()->with(['uploader:id,name'])->orderByDesc('created_at');
        if ($request->attributes->get('workspace_role') !== 'admin') {
            $q->where('uploaded_by', $request->user()->id);
        }
        if ($state = $request->query('state')) {
            $q->where('state', $state);
        }

        return response()->json(['data' => $q->limit(100)->get()->map(fn (Recording $r) => $this->present($r))]);
    }

    /** GET /v1/recordings/{id} — state, failure reason, derived document. Polled every 3 s while in flight (03 §7). */
    public function show(string $id): JsonResponse
    {
        $rec = Recording::query()->with('uploader:id,name')->findOrFail($id);
        $this->authorize('view', $rec);

        return response()->json(['data' => $this->present($rec)]);
    }

    /** PATCH /v1/recordings/{id}  body { title } */
    public function update(Request $request, string $id): JsonResponse
    {
        $rec = Recording::query()->findOrFail($id);
        $this->authorize('manage', $rec);
        $data = $request->validate(['title' => ['required', 'string', 'min:1', 'max:250']]);
        $rec->fill($data)->save();

        return response()->json(['data' => $this->present($rec)]);
    }

    /** GET /v1/recordings/{id}/playback-url — signed, TTL ≤ 15 min, after the policy check. */
    public function playbackUrl(string $id): JsonResponse
    {
        $rec = Recording::query()->findOrFail($id);
        $this->authorize('view', $rec);
        abort_if($rec->state === 'pending_upload', 409, 'Upload not complete.');

        return response()->json(['data' => ['url' => $this->storage->signedUrl($rec->storage_key, 900), 'expires_in' => 900]]);
    }

    /** POST /v1/recordings/{id}/retry — re-run the failed stage without re-uploading (F8). */
    public function retry(string $id): JsonResponse
    {
        $rec = Recording::query()->findOrFail($id);
        $this->authorize('manage', $rec);
        abort_unless($rec->state === 'failed', 409, 'Only a failed recording can be retried.');

        $stage = $rec->failed_stage ?? 'transcribe';
        $rec->forceFill(['state' => 'uploaded', 'failed_stage' => null, 'failure_reason' => null])->save();
        $this->dispatchFrom($rec, $stage);

        return response()->json(['data' => $this->present($rec)], 202);
    }

    /** POST /v1/recordings/{id}/generate — (re)run generation; a new draft is created, existing drafts untouched (F9). */
    public function generate(string $id): JsonResponse
    {
        $rec = Recording::query()->findOrFail($id);
        $this->authorize('manage', $rec);
        abort_if($rec->state === 'pending_upload' || $rec->inFlight(), 409, 'The recording is still being processed.');

        $rec->forceFill(['state' => 'uploaded', 'failed_stage' => null, 'failure_reason' => null])->save();
        // Regeneration reuses the transcript when one exists (Epic D); a fresh input hash keeps it distinct from the first run.
        TranscribeRecording::dispatch($rec->workspace_id, $rec->id, 'regen-'.now()->timestamp);

        return response()->json(['data' => ['recording_id' => $rec->id, 'stage' => 'transcribing', 'estimated_seconds' => (int) max(60, ($rec->duration_sec ?? 600) / 3)]], 202);
    }

    /** DELETE /v1/recordings/{id} — cascades to media, transcript, segments, frames (and embeddings from M3). */
    public function destroy(string $id): JsonResponse
    {
        $rec = Recording::query()->findOrFail($id);
        $this->authorize('manage', $rec);

        $this->storage->deletePrefix("{$rec->workspace_id}/{$rec->id}/");
        $rec->delete(); // FK cascades: transcript, segments, media_assets, pipeline_jobs; documents.source_recording_id → null
        Audit::record('recording.deleted', 'recording', $rec->id, ['title' => $rec->title]);

        return response()->json(['data' => ['id' => $id, 'deleted' => true]]);
    }

    private function dispatchFrom(Recording $rec, string $stage): void
    {
        // Until Epic D adds the later stages, every retry restarts from transcription; stage jobs are idempotent.
        TranscribeRecording::dispatch($rec->workspace_id, $rec->id, (string) $rec->size_bytes);
    }

    private function minutesLimit(Request $request, float $durationSec): ?JsonResponse
    {
        $plan = Workspace::query()->findOrFail($this->current->require())->plan;
        $limit = PlanLimits::for($plan, 'recording_minutes');
        if ($limit === null) {
            return null;
        }
        $used = (float) Recording::query()->where('created_at', '>=', now()->startOfMonth())->whereNotIn('state', ['pending_upload'])->sum('duration_sec') / 60;
        if ($used + $durationSec / 60 > $limit) {
            return response()->json(['error' => [
                'code' => 'plan_limit_exceeded',
                'message' => ucfirst($plan)." includes {$limit} recording minutes a month. Upgrade to record more.",
                'details' => ['limit' => 'recording_minutes', 'max' => $limit, 'used' => round($used, 1)],
            ]], 429);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function present(Recording $r): array
    {
        return [
            'id' => $r->id, 'space_id' => $r->space_id, 'title' => $r->title, 'state' => $r->state,
            'failed_stage' => $r->failed_stage, 'failure_reason' => $r->failure_reason,
            'mime_type' => $r->mime_type, 'size_bytes' => $r->size_bytes, 'duration_sec' => $r->duration_sec,
            'uploaded_by' => $r->relationLoaded('uploader') ? $r->uploader?->only(['id', 'name']) : ['id' => $r->uploaded_by],
            'document_id' => $r->document_id, 'created_at' => $r->created_at, 'updated_at' => $r->updated_at,
        ];
    }
}
