<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/** In-app only (doc 11 triggers: "Recording draft ready → Uploader → In-app"). */
final class RecordingDraftReadyNotification extends Notification
{
    public function __construct(public readonly string $title, public readonly string $recordingId, public readonly string $documentId) {}

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
        return ['type' => 'recording_draft_ready', 'title' => $this->title, 'recording_id' => $this->recordingId, 'document_id' => $this->documentId];
    }
}
