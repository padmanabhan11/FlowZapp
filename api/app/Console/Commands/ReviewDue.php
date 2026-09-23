<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ReviewDueNotification;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Console\Command;

/**
 * FR-413: documents past review_due_at are surfaced to their owner. Runs daily;
 * each owner is told once per document per week (the in-app notification is
 * deduplicated on document_id + the ISO week), the weekly digest carries email.
 */
final class ReviewDue extends Command
{
    protected $signature = 'documents:review-due';

    protected $description = 'Notify owners of documents that are due for review';

    public function handle(CurrentWorkspace $current): int
    {
        $sent = 0;
        foreach (Workspace::query()->whereNull('deletion_scheduled_at')->pluck('id') as $wsId) {
            $sent += $current->runAs($wsId, function () {
                $n = 0;
                $due = Document::query()->with('owner')->where('state', 'approved')->whereNotNull('owner_id')->where('review_due_at', '<=', now())->get();
                foreach ($due as $doc) {
                    /**
                     * @var User|null $owner
                     */
                    $owner = $doc->owner;
                    if ($owner === null) {
                        continue;
                    }
                    $already = $owner->notifications()->where('type', ReviewDueNotification::class)
                        ->where('created_at', '>=', now()->startOfWeek())->get()
                        ->contains(fn ($x) => ($x->data['document_id'] ?? null) === $doc->id);
                    if (! $already) {
                        $owner->notify(new ReviewDueNotification($doc->title, $doc->id, (string) $doc->review_due_at?->toIso8601String()));
                        $n++;
                    }
                }

                return $n;
            });
        }
        $this->info("Review-due notifications sent: {$sent}");

        return self::SUCCESS;
    }
}
