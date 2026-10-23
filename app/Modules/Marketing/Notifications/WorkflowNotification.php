<?php

namespace App\Modules\Marketing\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A workflow's “notify the manager” action: the author's words about one record, in-app and by e-mail. Plain values only. */
class WorkflowNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $about,     // "LD/AHD/26-27/00012 — Shree Ganesh Traders"
        public readonly string $message,
        public readonly string $workflow,
        public readonly string $url,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject("{$this->workflow}: {$this->about}")->greeting("Hello {$notifiable->name},")->line($this->message)->line("About: {$this->about}")->action('Open it', url($this->url));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => 'workflow', 'title' => $this->about, 'message' => $this->message, 'url' => $this->url];
    }
}
