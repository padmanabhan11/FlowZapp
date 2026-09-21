<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use App\Notifications\Support\NotificationPrefs;
use Illuminate\Notifications\Notification;

/**
 * Sent when a document that requires acknowledgement gets a new approved
 * version (FR-704) and when an admin nudges (FR-706). Same message either way:
 * what to read, which version, where.
 */
final class AcknowledgementDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $title, public readonly int $version, public readonly string $documentId, public readonly bool $reminder = false) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return NotificationPrefs::channels($notifiable, 'acknowledgement_due');
    }

    public function toMail(object $notifiable): MailMessage
    {
        $subject = ($this->reminder ? 'Reminder: ' : '')."Please read and acknowledge: {$this->title}";

        return (new MailMessage)->subject($subject)
            ->line("\"{$this->title}\" (v{$this->version}) requires your acknowledgement.")
            ->line('Acknowledgement is recorded against this version, with your name and the time.')
            ->action('Read and acknowledge', rtrim((string) config('flowzapp.frontend_url'), '/')."/handbook/{$this->documentId}");
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return ['type' => $this->reminder ? 'acknowledgement_reminder' : 'acknowledgement_due', 'title' => $this->title, 'version' => $this->version, 'document_id' => $this->documentId];
    }
}
