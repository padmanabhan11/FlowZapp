<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Media\MediaStorage;
use App\Models\Document;
use App\Models\Workspace;
use App\Retrieval\VectorStore;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Console\Command;

/**
 * S23: deletion happens after the grace period, not when the admin clicks.
 * Removes media under the workspace prefix and vectors, then the workspace row;
 * every tenant table cascades on workspace_id (doc 04).
 */
final class PurgeScheduledWorkspaces extends Command
{
    protected $signature = 'workspaces:purge-scheduled';

    protected $description = 'Delete workspaces whose scheduled deletion date has passed';

    public function handle(CurrentWorkspace $current, MediaStorage $media, VectorStore $vectors): int
    {
        $n = 0;
        foreach (Workspace::query()->whereNotNull('deletion_scheduled_at')->where('deletion_scheduled_at', '<=', now())->get() as $ws) {
            $current->runAs($ws->id, function () use ($ws, $media, $vectors): void {
                foreach (Document::query()->whereNotNull('approved_version_id')->pluck('id') as $docId) {
                    $vectors->deleteByDocument($ws->id, $docId);
                }
                $media->deletePrefix($ws->id.'/');
            });
            $ws->delete();
            $this->info("Deleted workspace {$ws->slug}");
            $n++;
        }
        $this->info("Workspaces purged: {$n}");

        return self::SUCCESS;
    }
}
