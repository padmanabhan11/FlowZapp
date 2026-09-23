<?php

declare(strict_types=1);

namespace App\Notifications\Support;

use App\Models\AcknowledgementTarget;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Tenancy\CurrentWorkspace;

/**
 * Per-workspace email preferences (S24). In-app (database) notifications are
 * always delivered; preferences only govern email. Acknowledgement-due cannot
 * be turned off while the person is assigned to acknowledge something in the
 * workspace (S24 rule) — the API reports that as `locked`.
 */
final class NotificationPrefs
{
    public const KEYS = ['review_requested', 'changes_requested', 'document_approved', 'acknowledgement_due', 'recording_failed', 'weekly_digest'];

    /** Email defaults (doc 11 "Notification triggers"): approved is in-app only by default. */
    public const DEFAULTS = ['review_requested' => true, 'changes_requested' => true, 'document_approved' => false, 'acknowledgement_due' => true, 'recording_failed' => true, 'weekly_digest' => true];

    /**
     * @return array{prefs: array<string,bool>, locked: list<string>}
     */
    public static function for(User $user, ?string $workspaceId = null): array
    {
        $workspaceId ??= app(CurrentWorkspace::class)->id();
        $prefs = self::DEFAULTS;
        $locked = [];
        if ($workspaceId !== null) {
            $m = WorkspaceMember::withoutGlobalScopes() // allowlisted: preferences are read from notification via() outside any request
                ->where('workspace_id', $workspaceId)->where('user_id', $user->id)->first();
            $prefs = array_merge($prefs, array_intersect_key($m?->notification_prefs ?? [], self::DEFAULTS));
            $assigned = AcknowledgementTarget::withoutGlobalScopes() // allowlisted: same — cross-context read by user id
                ->where('workspace_id', $workspaceId)->where('user_id', $user->id)->exists();
            if ($assigned) {
                $prefs['acknowledgement_due'] = true;
                $locked[] = 'acknowledgement_due';
            }
        }

        return ['prefs' => $prefs, 'locked' => $locked];
    }

    /**
     * Channels for a notification of the given kind: database always, mail when the preference allows.
     *
     * @return list<string>
     */
    public static function channels(object $notifiable, string $key): array
    {
        if (! $notifiable instanceof User) {
            return ['mail', 'database'];
        }
        $allowed = self::for($notifiable)['prefs'][$key] ?? true;

        return $allowed ? ['mail', 'database'] : ['database'];
    }
}
