<?php

namespace App\Modules\Shared\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "X is waiting for your approval" — in-app and e-mail. Carries plain values only, so it queues safely. */
class ApprovalRequestedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $title,
        public readonly ?string $url,
        public readonly string $requestedBy,
        public readonly int $level,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("Approval needed: {$this->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->requestedBy} submitted {$this->title} for your approval (level {$this->level}).");

        return $this->url ? $mail->action('Review and decide', url($this->url)) : $mail;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'approval_requested',
            'title' => $this->title,
            'message' => "{$this->requestedBy} submitted {$this->title} for your approval.",
            'url' => $this->url,
        ];
    }
}
