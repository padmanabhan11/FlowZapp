<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class ReviewRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $title, public readonly string $submitter, public readonly string $documentId) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("Review requested: {$this->title}")
            ->line("{$this->submitter} submitted \"{$this->title}\" for approval.")
            ->action('Review', rtrim((string) config('flowzapp.frontend_url'), '/')."/d/{$this->documentId}/review");
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return ['type' => 'review_requested', 'title' => $this->title, 'document_id' => $this->documentId, 'by' => $this->submitter];
    }
}
