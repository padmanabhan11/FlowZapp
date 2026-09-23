<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\Pipeline\IndexDocument;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Notifications\IndexUnhealthyNotification;
use App\Retrieval\Deindex;
use App\Retrieval\IndexHealth;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * G1-T5 unindexed-approved-document monitor (doc 04: "anything else is an
 * alerting condition, not a warning"). Every ten minutes, per workspace:
 *
 *   - approved documents with no indexed chunks for their approved version
 *     are re-queued for indexing;
 *   - those still unindexed longer than retrieval.unindexed_alert_minutes
 *     after approval raise an error log line (the alert) and an in-app
 *     notice to workspace admins, at most once a day per document;
 *   - chunks left behind by archived/draft documents are removed, and
 *     documents with chunks from a superseded version are re-indexed.
 */
final class CheckIndex extends Command
{
    protected $signature = 'retrieval:check-index {--dry-run : report only, change nothing}';

    protected $description = 'Find approved documents missing from the retrieval index, re-queue them, and alert';

    public function handle(CurrentWorkspace $current): int
    {
        $dry = (bool) $this->option('dry-run');
        $alertAfter = (int) config('flowzapp.retrieval.unindexed_alert_minutes', 30);
        $totals = ['unindexed' => 0, 'alerted' => 0, 'removed' => 0, 'reindexed' => 0];

        foreach (Workspace::query()->whereNull('deletion_scheduled_at')->pluck('id') as $wsId) {
            $current->runAs($wsId, function () use ($wsId, $dry, $alertAfter, &$totals): void {
                $missing = IndexHealth::unindexed();
                $overdue = [];
                foreach ($missing as $doc) {
                    $totals['unindexed']++;
                    if (! $dry) {
                        IndexDocument::dispatch($wsId, $doc->id, (string) $doc->approved_version_id);
                    }
                    $approvedAt = $doc->approvedVersion?->approved_at;
                    if ($approvedAt !== null && $approvedAt->lt(now()->subMinutes($alertAfter))
                        && Cache::add("index-alert:{$doc->id}:".now()->toDateString(), 1, now()->addDay())) {
                        $overdue[] = ['document_id' => $doc->id, 'title' => $doc->title];
                    }
                }
                if ($overdue !== []) {
                    $totals['alerted'] += count($overdue);
                    Log::channel('alerts')->error('retrieval.unindexed_approved_documents', ['workspace_id' => $wsId, 'documents' => array_column($overdue, 'document_id'), 'alert_after_minutes' => $alertAfter]);
                    if (! $dry) {
                        $admins = User::query()->whereIn('id', WorkspaceMember::query()->where('role', 'admin')->pluck('user_id'))->get();
                        foreach ($admins as $admin) {
                            $admin->notify(new IndexUnhealthyNotification($overdue));
                        }
                    }
                }

                $stale = IndexHealth::stale();
                foreach ($stale['not_approved'] as $docId) {
                    $totals['removed']++;
                    if (! $dry) {
                        Deindex::document($wsId, $docId);
                    }
                }
                foreach ($stale['superseded'] as $docId) {
                    $totals['reindexed']++;
                    $doc = Document::query()->find($docId);
                    if (! $dry && $doc?->approved_version_id) {
                        IndexDocument::dispatch($wsId, $doc->id, $doc->approved_version_id);
                    }
                }
            });
        }

        $this->info(sprintf('%d unindexed approved (%d alerted), %d stale removed, %d superseded re-queued%s',
            $totals['unindexed'], $totals['alerted'], $totals['removed'], $totals['reindexed'], $dry ? ' — dry run' : ''));

        return self::SUCCESS;
    }
}
