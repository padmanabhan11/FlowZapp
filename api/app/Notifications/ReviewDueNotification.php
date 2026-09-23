<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** FR-413: "Review due → Document owner → In-app, weekly digest". In-app here; the digest carries the email. */
final class ReviewDueNotification extends Notification
{
    public function __construct(public readonly string $title, public readonly string $documentId, public readonly string $dueAt) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['type' => 'review_due', 'title' => $this->title, 'document_id' => $this->documentId, 'due_at' => $this->dueAt];
    }
}
