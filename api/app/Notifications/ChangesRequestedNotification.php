<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Support\NotificationPrefs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ChangesRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $title, public readonly string $comment, public readonly string $documentId) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return NotificationPrefs::channels($notifiable, 'changes_requested');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("Changes requested: {$this->title}")
            ->line("The reviewer asked for changes to \"{$this->title}\":")
            ->line($this->comment)
            ->action('Open the draft', rtrim((string) config('flowzapp.frontend_url'), '/')."/d/{$this->documentId}/edit");
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return ['type' => 'changes_requested', 'title' => $this->title, 'document_id' => $this->documentId, 'comment' => $this->comment];
    }
}
