<?php

namespace App\Modules\CRM\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "Your follow-up is coming up" — in-app and e-mail, sent once per follow-up by the scheduler. Plain values only. */
class FollowupDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $what,      // "Call"
        public readonly string $with,      // "LD/AHD/26-27/00012 — Shree Ganesh Traders"
        public readonly string $when,      // "13-10-2026 15:30"
        public readonly bool $late,
        public readonly string $url,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function headline(): string
    {
        return $this->late
            ? "{$this->what} with {$this->with} was due at {$this->when}"
            : "{$this->what} with {$this->with} is due at {$this->when}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(($this->late ? 'Overdue follow-up: ' : 'Follow-up due: ').$this->with)
            ->greeting("Hello {$notifiable->name},")
            ->line($this->headline().'.')
            ->action('Open the lead', url($this->url));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->late ? 'followup_overdue' : 'followup_due',
            'title' => $this->with,
            'message' => $this->headline().'.',
            'url' => $this->url,
        ];
    }
}
