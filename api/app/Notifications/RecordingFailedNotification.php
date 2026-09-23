<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Notifications\Support\NotificationPrefs;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Recording failed → Uploader → In-app, email". The message is the plain-language reason from the pipeline (S10). */
final class RecordingFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $title, public readonly string $reason, public readonly string $recordingId) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return NotificationPrefs::channels($notifiable, 'recording_failed');
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("Recording could not be processed: {$this->title}")
            ->line($this->reason)
            ->action('Open recordings', rtrim((string) config('flowzapp.frontend_url'), '/').'/recordings');
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(object $notifiable): array
    {
        return ['type' => 'recording_failed', 'title' => $this->title, 'recording_id' => $this->recordingId, 'reason' => $this->reason];
    }
}
