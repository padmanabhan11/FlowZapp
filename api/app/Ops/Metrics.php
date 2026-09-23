<?php

declare(strict_types=1);

namespace App\Ops;

use App\Models\ChatMessage;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Models\PipelineJob;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Support\Facades\DB;

/**
 * M4-T3 operational metrics across all workspaces: pipeline success and
 * latency per stage, cost per generated SOP, index backlog, chat refusal
 * rate. Computed on demand from the tables (small enough per window) and
 * exposed as Prometheus text by /internal/metrics for the scraper, and used
 * by ops:check-health for alerts. Reads cross every workspace, so the
 * scope bypass is allowlisted here and nowhere else.
 */
final class Metrics
{
    public function __construct(private readonly CurrentWorkspace $current) {}

    /**
     * @return array{
     *   window_hours: int,
     *   stages: array<string, array{total: int, succeeded: int, failed: int, success_rate: ?float, p50_ms: ?int, p95_ms: ?int}>,
     *   generation: array{sops: int, cost_usd: float, cost_per_sop_usd: ?float, failed: int, halted: int, failure_rate: ?float},
     *   index: array{unindexed_documents: int, oldest_unindexed_minutes: ?int, failed_index_jobs: int},
     *   chat: array{answers: int, refused: int, refusal_rate: ?float, p95_latency_ms: ?int},
     *   workspaces: int
     * }
     */
    public function snapshot(int $windowHours = 24): array
    {
        $since = now()->subHours($windowHours);
        $jobs = PipelineJob::withoutGlobalScopes()->where('created_at', '>=', $since)->get(['stage', 'status', 'cost_usd', 'error', 'started_at', 'finished_at']); // allowlisted: platform metrics span all workspaces (read only)

        $stages = [];
        foreach (['transcribe', 'segment', 'frames', 'generate', 'embed'] as $stage) {
            $rows = $jobs->where('stage', $stage);
            $done = $rows->whereIn('status', ['succeeded', 'failed']);
            $durations = $rows->where('status', 'succeeded')->map(fn ($j) => $j->started_at && $j->finished_at ? (int) $j->started_at->diffInMilliseconds($j->finished_at) : null)->filter()->sort()->values();
            $stages[$stage] = [
                'total' => $rows->count(), 'succeeded' => $rows->where('status', 'succeeded')->count(), 'failed' => $rows->where('status', 'failed')->count(),
                'success_rate' => $done->count() ? round($rows->where('status', 'succeeded')->count() / $done->count(), 4) : null,
                'p50_ms' => self::percentile($durations->all(), 0.5), 'p95_ms' => self::percentile($durations->all(), 0.95),
            ];
        }
        $gen = $jobs->where('stage', 'generate');
        $sops = $gen->where('status', 'succeeded')->count();
        $failed = $gen->where('status', 'failed');
        $halted = $failed->filter(fn ($j) => str_contains((string) $j->error, 'Record it again') || str_contains((string) $j->error, 'clearer narration'))->count();
        $pipelineCost = (float) $jobs->where('status', 'succeeded')->sum('cost_usd');

        $unindexed = 0;
        $oldest = null;
        foreach (Workspace::query()->whereNull('deletion_scheduled_at')->pluck('id') as $wsId) {
            $this->current->runAs($wsId, function () use (&$unindexed, &$oldest): void {
                $live = Document::query()->live()->with('approvedVersion:id,approved_at')->get(['id', 'approved_version_id']);
                if ($live->isEmpty()) {
                    return;
                }
                $indexed = DocumentChunk::query()->whereIn('version_id', $live->pluck('approved_version_id'))->whereNotNull('indexed_at')->distinct()->pluck('version_id')->flip();
                foreach ($live as $d) {
                    if (! $indexed->has((string) $d->approved_version_id)) {
                        $unindexed++;
                        $age = $d->approvedVersion?->approved_at ? (int) $d->approvedVersion->approved_at->diffInMinutes(now()) : null;
                        if ($age !== null && ($oldest === null || $age > $oldest)) {
                            $oldest = $age;
                        }
                    }
                }
            });
        }
        $failedIndex = (int) DB::table('failed_jobs')->where('failed_at', '>=', $since)->where('payload', 'like', '%IndexDocument%')->count(); // allowlisted: failed_jobs is a framework table with no tenant

        $answers = ChatMessage::withoutGlobalScopes()->where('role', 'assistant')->where('created_at', '>=', $since)->get(['refused', 'latency_ms']); // allowlisted: platform metrics span all workspaces (read only)
        $latencies = $answers->pluck('latency_ms')->filter()->sort()->values()->all();

        return [
            'window_hours' => $windowHours,
            'stages' => $stages,
            'generation' => ['sops' => $sops, 'cost_usd' => round($pipelineCost, 4), 'cost_per_sop_usd' => $sops ? round($pipelineCost / $sops, 4) : null,
                'failed' => $failed->count() - $halted, 'halted' => $halted, 'failure_rate' => ($sops + $failed->count() - $halted) ? round(($failed->count() - $halted) / ($sops + $failed->count() - $halted), 4) : null],
            'index' => ['unindexed_documents' => $unindexed, 'oldest_unindexed_minutes' => $oldest, 'failed_index_jobs' => $failedIndex],
            'chat' => ['answers' => $answers->count(), 'refused' => $answers->where('refused', true)->count(),
                'refusal_rate' => $answers->count() ? round($answers->where('refused', true)->count() / $answers->count(), 4) : null, 'p95_latency_ms' => self::percentile($latencies, 0.95)],
            'workspaces' => Workspace::query()->whereNull('deletion_scheduled_at')->count(),
        ];
    }

    /** Prometheus text exposition of snapshot(). */
    public function prometheus(int $windowHours = 24): string
    {
        $s = $this->snapshot($windowHours);
        $l = ['# FlowZapp metrics, window '.$windowHours.'h (M4-T3)'];
        foreach ($s['stages'] as $stage => $m) {
            foreach (['total', 'succeeded', 'failed'] as $k) {
                $l[] = "flowzapp_pipeline_jobs{stage=\"{$stage}\",result=\"{$k}\"} {$m[$k]}";
            }
            $l[] = "flowzapp_pipeline_success_rate{stage=\"{$stage}\"} ".($m['success_rate'] ?? 'NaN');
            $l[] = "flowzapp_pipeline_duration_ms{stage=\"{$stage}\",quantile=\"0.5\"} ".($m['p50_ms'] ?? 'NaN');
            $l[] = "flowzapp_pipeline_duration_ms{stage=\"{$stage}\",quantile=\"0.95\"} ".($m['p95_ms'] ?? 'NaN');
        }
        $g = $s['generation'];
        $l[] = "flowzapp_generation_sops {$g['sops']}";
        $l[] = "flowzapp_generation_cost_usd {$g['cost_usd']}";
        $l[] = 'flowzapp_generation_cost_per_sop_usd '.($g['cost_per_sop_usd'] ?? 'NaN');
        $l[] = "flowzapp_generation_failed {$g['failed']}";
        $l[] = "flowzapp_generation_halted {$g['halted']}";
        $l[] = 'flowzapp_generation_failure_rate '.($g['failure_rate'] ?? 'NaN');
        $l[] = "flowzapp_index_unindexed_documents {$s['index']['unindexed_documents']}";
        $l[] = 'flowzapp_index_oldest_unindexed_minutes '.($s['index']['oldest_unindexed_minutes'] ?? 0);
        $l[] = "flowzapp_index_failed_jobs {$s['index']['failed_index_jobs']}";
        $l[] = "flowzapp_chat_answers {$s['chat']['answers']}";
        $l[] = "flowzapp_chat_refused {$s['chat']['refused']}";
        $l[] = 'flowzapp_chat_refusal_rate '.($s['chat']['refusal_rate'] ?? 'NaN');
        $l[] = 'flowzapp_chat_latency_ms{quantile="0.95"} '.($s['chat']['p95_latency_ms'] ?? 'NaN');
        $l[] = "flowzapp_workspaces {$s['workspaces']}";

        return implode("\n", $l)."\n";
    }

    /**
     * @param  list<int>  $sorted
     */
    private static function percentile(array $sorted, float $p): ?int
    {
        if ($sorted === []) {
            return null;
        }

        return $sorted[min(count($sorted) - 1, (int) ceil($p * count($sorted)) - 1)];
    }
}
