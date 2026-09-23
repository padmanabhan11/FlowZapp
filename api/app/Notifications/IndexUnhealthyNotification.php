<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * G1-T5: approved documents that search and the assistant cannot see.
 * In-app to workspace admins; the error log line is what paging keys on.
 */
final class IndexUnhealthyNotification extends Notification
{
    /**
     * @param  list<array{document_id: string, title: string}>  $documents
     */
    public function __construct(public readonly array $documents) {}

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
        return ['type' => 'index_unhealthy', 'count' => count($this->documents), 'documents' => array_slice($this->documents, 0, 20),
            'document_id' => $this->documents[0]['document_id'] ?? null, 'title' => $this->documents[0]['title'] ?? null];
    }
}
