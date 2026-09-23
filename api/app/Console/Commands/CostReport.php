<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Billing\PlanLimits;
use App\Models\AuditEntry;
use App\Models\ChatMessage;
use App\Models\PipelineJob;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * L4-T2 — operator cost view: model spend per workspace for a month, against
 * what the plan brings in (BR-32 margin check). Internal only: it runs from
 * the console, never through the API, because it crosses every workspace.
 *
 *   php artisan ops:cost-report                 # this month
 *   php artisan ops:cost-report --month=2026-08 --csv=storage/app/cost-2026-08.csv
 *
 * Sources: pipeline_jobs.cost_usd (transcribe, segment, frames, generate —
 * the cost of a generated SOP), chat_messages.cost_usd (answers and
 * follow-up rewrites), audit metadata cost_usd for rewrite / translate /
 * title suggestions. Plan revenue is the list price (pricing decision
 * 18 Sep 2026); the payment provider's real invoices replace it once connected.
 */
final class CostReport extends Command
{
    protected $signature = 'ops:cost-report {--month= : YYYY-MM, default this month} {--csv= : also write a CSV to this path}';

    protected $description = 'Model cost per workspace and per generated SOP for a month (operator view)';

    /** List prices in USD used for the margin column until real invoices exist. */
    private const PRICE = ['free' => 0.0, 'pro' => 29.0, 'team' => 99.0];

    public function handle(CurrentWorkspace $current): int
    {
        $month = $this->option('month') ? Carbon::createFromFormat('Y-m', (string) $this->option('month')) : now();
        if ($month === null) {
            $this->error('--month must be YYYY-MM');

            return self::FAILURE;
        }
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth();

        $rows = [];
        foreach (Workspace::query()->orderBy('name')->get() as $ws) {
            $rows[] = $current->runAs($ws->id, function () use ($ws, $from, $to): array {
                $jobs = PipelineJob::query()->whereBetween('created_at', [$from, $to])->get(['recording_id', 'stage', 'status', 'cost_usd']);
                $sops = $jobs->where('stage', 'generate')->where('status', 'succeeded')->count();
                $pipeline = round((float) $jobs->sum('cost_usd'), 4);
                $answers = ChatMessage::query()->where('role', 'assistant')->whereBetween('created_at', [$from, $to])->get(['cost_usd']);
                $chat = round((float) $answers->sum('cost_usd'), 4);
                $assist = round((float) AuditEntry::query()->whereIn('action', ['ai.rewrite_proposed', 'ai.translated', 'ai.title_suggested'])
                    ->whereBetween('created_at', [$from, $to])->get(['metadata'])->sum(fn (AuditEntry $a) => (float) ($a->metadata['cost_usd'] ?? 0)), 4);
                $total = round($pipeline + $chat + $assist, 4);
                $price = self::PRICE[$ws->plan] ?? 0.0;

                return [
                    'workspace' => $ws->name, 'plan' => $ws->plan, 'sops' => $sops,
                    'pipeline_usd' => $pipeline, 'per_sop_usd' => $sops ? round($pipeline / $sops, 4) : null,
                    'answers' => $answers->count(), 'chat_usd' => $chat, 'assist_usd' => $assist, 'total_usd' => $total,
                    'plan_usd' => $price, 'margin_pct' => $price > 0 ? round(100 * ($price - $total) / $price, 1) : null,
                    'sop_cap' => PlanLimits::for($ws->plan, 'sop_generations'),
                ];
            });
        }

        $this->info("Model cost by workspace — {$from->format('F Y')}");
        $this->table(['Workspace', 'Plan', 'SOPs', 'Pipeline $', '$ / SOP', 'Answers', 'Chat $', 'Assist $', 'Total $', 'Plan $', 'Margin %'],
            array_map(fn ($r) => [$r['workspace'], $r['plan'], $r['sops'], $r['pipeline_usd'], $r['per_sop_usd'] ?? '—', $r['answers'], $r['chat_usd'], $r['assist_usd'], $r['total_usd'], $r['plan_usd'], $r['margin_pct'] ?? '—'], $rows));
        $totalSops = array_sum(array_column($rows, 'sops'));
        $totalPipeline = array_sum(array_column($rows, 'pipeline_usd'));
        $this->line(sprintf('All workspaces: %d SOPs, $%.4f pipeline (avg $%s per SOP), $%.4f chat, $%.4f assist.',
            $totalSops, $totalPipeline, $totalSops ? number_format($totalPipeline / $totalSops, 4) : '—', array_sum(array_column($rows, 'chat_usd')), array_sum(array_column($rows, 'assist_usd'))));

        if ($this->option('csv')) {
            $fh = fopen((string) $this->option('csv'), 'w');
            if ($fh !== false) {
                fputcsv($fh, array_keys($rows[0] ?? ['workspace' => null]), ',', '"', '');
                foreach ($rows as $r) {
                    fputcsv($fh, array_map(fn ($v) => (string) ($v ?? ''), $r), ',', '"', '');
                }
                fclose($fh);
                $this->info('CSV written to '.$this->option('csv'));
            }
        }

        return self::SUCCESS;
    }
}
