<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Weekly digest (S24 preference): documents due for review that the person
 * owns, and acknowledgements they still owe. Sent only when there is
 * something in it.
 *
 * @phpstan-type Item array{title: string, document_id: string, due_at?: string|null, version_number?: int|null}
 */
final class WeeklyDigestNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  list<Item>  $reviewDue
     * @param  list<Item>  $acksDue
     */
    public function __construct(public readonly string $workspaceName, public readonly array $reviewDue, public readonly array $acksDue) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $base = rtrim((string) config('flowzapp.frontend_url'), '/');
        $m = (new MailMessage)->subject("{$this->workspaceName}: your week in FlowZapp");
        if ($this->reviewDue !== []) {
            $m->line('Documents you own that are due for review:');
            foreach ($this->reviewDue as $d) {
                $m->line("• {$d['title']} — due ".substr((string) ($d['due_at'] ?? ''), 0, 10)." — {$base}/d/{$d['document_id']}");
            }
        }
        if ($this->acksDue !== []) {
            $m->line('Documents waiting for your acknowledgement:');
            foreach ($this->acksDue as $d) {
                $m->line("• {$d['title']} (v".($d['version_number'] ?? '?').") — {$base}/handbook/{$d['document_id']}");
            }
        }

        return $m->action('Open FlowZapp', $base);
    }
}
