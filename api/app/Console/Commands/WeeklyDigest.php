<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Acknowledgement;
use App\Models\AcknowledgementTarget;
use App\Models\Document;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Notifications\Support\NotificationPrefs;
use App\Notifications\WeeklyDigestNotification;
use App\Tenancy\CurrentWorkspace;
use Illuminate\Console\Command;

/** S24 "weekly digest": review-due documents you own + acknowledgements you owe, per workspace, only when non-empty. */
final class WeeklyDigest extends Command
{
    protected $signature = 'notifications:weekly-digest';

    protected $description = 'Email each member a digest of what they owe this week';

    public function handle(CurrentWorkspace $current): int
    {
        $sent = 0;
        foreach (Workspace::query()->whereNull('deletion_scheduled_at')->get() as $ws) {
            $sent += $current->runAs($ws->id, function () use ($ws): int {
                $n = 0;
                foreach (WorkspaceMember::query()->with('user')->get() as $m) {
                    $user = $m->user;
                    if ($user === null || ! (NotificationPrefs::for($user, $ws->id)['prefs']['weekly_digest'] ?? true)) {
                        continue;
                    }
                    $review = Document::query()->where('state', 'approved')->where('owner_id', $user->id)->where('review_due_at', '<=', now())->get()
                        ->map(fn (Document $d) => ['title' => $d->title, 'document_id' => $d->id, 'due_at' => $d->review_due_at?->toIso8601String()])->values()->all();
                    $targets = AcknowledgementTarget::query()->where('user_id', $user->id)->pluck('document_id');
                    $docs = Document::query()->with('approvedVersion:id,version_number')->whereIn('id', $targets)->where('state', 'approved')->whereNotNull('approved_version_id')->get();
                    $done = Acknowledgement::query()->where('user_id', $user->id)->whereIn('version_id', $docs->pluck('approved_version_id'))->pluck('version_id')->all();
                    $acks = $docs->reject(fn (Document $d) => in_array($d->approved_version_id, $done, true))
                        ->map(fn (Document $d) => ['title' => $d->title, 'document_id' => $d->id, 'version_number' => $d->approvedVersion?->version_number])->values()->all();
                    if ($review === [] && $acks === []) {
                        continue;
                    }
                    $user->notify(new WeeklyDigestNotification($ws->name, $review, $acks));
                    $n++;
                }

                return $n;
            });
        }
        $this->info("Digests sent: {$sent}");

        return self::SUCCESS;
    }
}
