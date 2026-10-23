<?php

namespace App\Modules\HR\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * One notice from HR & workforce, in-app and by e-mail: leave asked for or decided, a correction asked for or decided, an interview booked, a review waiting. Plain values only
 * (it is queued): `kind` is a stable code the bell can group by, `message` the sentence, `url` where to go.
 */
class HrNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $kind,
        public readonly string $subject,
        public readonly string $message,
        public readonly string $url,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->subject)->greeting("Hello {$notifiable->name},")->line($this->message)->action('Open it', url($this->url));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'title' => $this->subject, 'message' => $this->message, 'url' => $this->url];
    }
}
