<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** In-app only (11 "Notification triggers": Approved → in-app). */
final class DocumentApprovedNotification extends Notification
{
    public function __construct(public readonly string $title, public readonly int $version, public readonly string $documentId) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string,mixed> */
    public function toArray(object $notifiable): array
    {
        return ['type' => 'document_approved', 'title' => $this->title, 'version' => $this->version, 'document_id' => $this->documentId];
    }
}
