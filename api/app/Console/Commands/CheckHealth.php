<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Ops\Metrics;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * M4-T4 alerts, every 10 minutes, on the `alerts` channel (infra/runbooks):
 *   - generation failures: over ops.generation_failure_rate of generate jobs
 *     failed in the last hour (halts for unusable recordings do not count);
 *   - embedding backlog: more than ops.index_backlog documents unindexed, or
 *     the oldest older than ops.index_backlog_minutes, or IndexDocument jobs
 *     in failed_jobs;
 *   - unindexed approved documents per workspace are raised by
 *     retrieval:check-index (G1-T5).
 * Each condition alerts at most once an hour.
 */
final class CheckHealth extends Command
{
    protected $signature = 'ops:check-health {--window=1 : hours of pipeline history to judge}';

    protected $description = 'Raise operator alerts for generation failures and embedding backlog';

    public function handle(Metrics $metrics): int
    {
        $s = $metrics->snapshot((int) $this->option('window'));
        $raised = [];

        $g = $s['generation'];
        $attempts = $g['sops'] + $g['failed'];
        $minAttempts = (int) config('flowzapp.ops.generation_min_attempts', 3);
        $maxRate = (float) config('flowzapp.ops.generation_failure_rate', 0.25);
        if ($attempts >= $minAttempts && ($g['failure_rate'] ?? 0) > $maxRate) {
            $raised[] = $this->raise('pipeline.generation_failures', ['failed' => $g['failed'], 'succeeded' => $g['sops'], 'failure_rate' => $g['failure_rate'], 'window_hours' => $s['window_hours']]);
        }

        $i = $s['index'];
        if ($i['unindexed_documents'] > (int) config('flowzapp.ops.index_backlog', 10)
            || ($i['oldest_unindexed_minutes'] ?? 0) > (int) config('flowzapp.ops.index_backlog_minutes', 60)
            || $i['failed_index_jobs'] > 0) {
            $raised[] = $this->raise('retrieval.embedding_backlog', $i);
        }

        $raised = array_values(array_filter($raised));
        $this->info(($raised === [] ? 'healthy' : 'alerts: '.implode(', ', $raised))." — generation {$g['sops']} ok / {$g['failed']} failed / {$g['halted']} halted; unindexed {$i['unindexed_documents']}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $ctx
     */
    private function raise(string $name, array $ctx): ?string
    {
        if (! Cache::add("ops-alert:{$name}", 1, now()->addHour())) {
            return null;   // already raised this hour
        }
        Log::channel('alerts')->error($name, $ctx);

        return $name;
    }
}
