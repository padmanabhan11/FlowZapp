<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Audit\Audit;
use App\Http\Controllers\Controller;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * S21 — the audit log, filterable and exportable, never editable (FR-414,
 * FR-912, BRL-11). There is no write endpoint here on purpose; the table is
 * INSERT/SELECT-only for the application user.
 */
final class AuditLogController extends Controller
{
    /** GET /v1/audit-log?entity_type=&actor_id=&action=&from=&to=&cursor=&limit= — newest first, cursor = last id. */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('workspace-admin');
        $f = $this->filters($request);
        $limit = min(200, max(1, (int) $request->query('limit', '50')));
        $q = $this->query($f);
        if ($cursor = $request->query('cursor')) {
            $q->where('id', '<', (int) $cursor);
        }
        $rows = $q->limit($limit + 1)->get();
        $more = $rows->count() > $limit;
        $rows = $rows->take($limit);
        $actors = User::query()->whereIn('id', $rows->pluck('actor_id')->filter()->unique())->get(['id', 'name', 'email'])->keyBy('id');

        return response()->json([
            'data' => $rows->map(fn (AuditEntry $e) => $this->present($e, $actors->get($e->actor_id))),
            'next_cursor' => $more ? $rows->last()?->id : null,
            'actions' => AuditEntry::query()->select('action')->distinct()->orderBy('action')->pluck('action'),
        ]);
    }

    /** GET /v1/audit-log/export?… — CSV of the current filter (FR-912). The export is itself audited (FR-414). */
    public function export(Request $request): StreamedResponse
    {
        $this->authorize('workspace-admin');
        $f = $this->filters($request);
        Audit::record('audit_log.exported', 'workspace', app(\App\Tenancy\CurrentWorkspace::class)->require(), array_filter($f));
        $q = $this->query($f);

        return response()->streamDownload(function () use ($q): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['id', 'timestamp_utc', 'actor_id', 'actor_name', 'actor_email', 'action', 'entity_type', 'entity_id', 'ip', 'metadata']);
            $q->chunk(500, function ($rows) use ($out): void {
                $actors = User::query()->whereIn('id', $rows->pluck('actor_id')->filter()->unique())->get(['id', 'name', 'email'])->keyBy('id');
                foreach ($rows as $e) {
                    $a = $actors->get($e->actor_id);
                    fputcsv($out, [$e->id, $e->created_at?->toIso8601String(), $e->actor_id, $a?->name, $a?->email, $e->action, $e->entity_type, $e->entity_id, self::ip($e->ip), json_encode($e->metadata)]);
                }
            });
            fclose($out);
        }, 'audit-log-'.now()->format('Ymd-Hi').'.csv', ['Content-Type' => 'text/csv']);
    }

    /** @return array<string, string|null> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'entity_type' => ['nullable', 'string', 'max:60'], 'actor_id' => ['nullable', 'string', 'size:26'],
            'action' => ['nullable', 'string', 'max:80'], 'from' => ['nullable', 'date'], 'to' => ['nullable', 'date'],
        ]);
    }

    /**
     * @param  array<string, string|null>  $f
     * @return Builder<AuditEntry>
     */
    private function query(array $f): Builder
    {
        return AuditEntry::query()->orderByDesc('id')
            ->when(! empty($f['entity_type']), fn ($q) => $q->where('entity_type', $f['entity_type']))
            ->when(! empty($f['actor_id']), fn ($q) => $q->where('actor_id', $f['actor_id']))
            ->when(! empty($f['action']), fn ($q) => $q->where('action', 'like', $f['action'].'%'))
            ->when(! empty($f['from']), fn ($q) => $q->where('created_at', '>=', $f['from']))
            ->when(! empty($f['to']), fn ($q) => $q->where('created_at', '<', \Illuminate\Support\Carbon::parse($f['to'])->addDay()));
    }

    /** @return array<string, mixed> */
    private function present(AuditEntry $e, ?User $actor): array
    {
        return [
            'id' => $e->id, 'at' => $e->created_at, 'actor' => $actor?->only(['id', 'name', 'email']), 'action' => $e->action,
            'entity_type' => $e->entity_type, 'entity_id' => $e->entity_id, 'metadata' => $e->metadata, 'ip' => self::ip($e->ip),
        ];
    }

    private static function ip(mixed $packed): ?string
    {
        if ($packed === null || $packed === '') {
            return null;
        }

        return @inet_ntop((string) $packed) ?: null;
    }
}
