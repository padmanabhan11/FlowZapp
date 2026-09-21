<?php

declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class WorkspaceInviteNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $workspaceName,
        private readonly string $inviterName,
        private readonly string $role,
        private readonly string $acceptUrl,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->inviterName} invited you to {$this->workspaceName} on FlowZapp")
            ->line("{$this->inviterName} has invited you to join {$this->workspaceName} as {$this->role}.")
            ->action("Join {$this->workspaceName}", $this->acceptUrl)
            ->line('The invitation expires in 7 days.');
    }
}
