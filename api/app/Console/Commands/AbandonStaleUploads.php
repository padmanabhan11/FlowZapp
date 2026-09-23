<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Media\MediaStorage;
use App\Models\Recording;
use App\Models\Workspace;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Console\Command;

/**
 * Uploads can be resumed for a week (C3). After that the multipart upload is
 * aborted (storage stops charging for orphaned parts) and the row removed.
 */
final class AbandonStaleUploads extends Command
{
    protected $signature = 'recordings:abandon-stale {--days=7}';

    protected $description = 'Abort multipart uploads left in pending_upload for more than N days';

    public function handle(CurrentWorkspace $current, MediaStorage $storage): int
    {
        $cutoff = now()->subDays((int) $this->option('days'));
        $n = 0;
        foreach (Workspace::query()->pluck('id') as $wsId) {
            $n += $current->runAs($wsId, function () use ($cutoff, $storage): int {
                $count = 0;
                foreach (Recording::query()->where('state', 'pending_upload')->where('created_at', '<', $cutoff)->get() as $rec) {
                    if ($rec->upload_id) {
                        try {
                            $storage->abortMultipartUpload($rec->storage_key, (string) $rec->upload_id);
                        } catch (\Throwable) {
                            // already gone at the provider; remove the row regardless
                        }
                    }
                    $rec->delete();
                    $count++;
                }

                return $count;
            });
        }
        $this->info("Abandoned uploads removed: {$n}");

        return self::SUCCESS;
    }
}
