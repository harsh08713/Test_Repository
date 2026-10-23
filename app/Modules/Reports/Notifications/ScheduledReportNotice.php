<?php

namespace App\Modules\Reports\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A scheduled report sent to its owner (with the file attached), or a sentence saying it could not be made or has been paused. Not queued: the file must still exist when it is sent. */
class ScheduledReportNotice extends Notification
{
    public function __construct(
        public readonly string $kind,
        public readonly string $subject,
        public readonly string $message,
        public readonly string $url,
        public readonly ?string $disk = null,
        public readonly ?string $path = null,
        public readonly ?string $fileName = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject)->greeting("Hello {$notifiable->name},")->line($this->message)->action('Open it', url($this->url));

        if ($this->path !== null && $this->disk !== null) {
            $mail->attachFromStorageDisk($this->disk, $this->path, $this->fileName);
        }

        return $mail;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'title' => $this->subject, 'message' => $this->message, 'url' => $this->url];
    }
}
