<?php

namespace App\Modules\CRM\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "A follow-up on your team has been left overdue" — to the manager, in-app and e-mail, once per follow-up. Plain values only. */
class FollowupOverdueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $person,    // "Bhavin BDE"
        public readonly string $what,      // "Call"
        public readonly string $with,      // "LD/AHD/26-27/00012 — Shree Ganesh Traders"
        public readonly string $when,      // "13-10-2026 15:30"
        public readonly string $url,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function headline(): string
    {
        return "{$this->person}’s ".strtolower($this->what)." with {$this->with} was due at {$this->when} and has not been done";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Overdue follow-up on your team: {$this->with}")
            ->greeting("Hello {$notifiable->name},")
            ->line($this->headline().'.')
            ->action('Open the lead', url($this->url));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'followup_escalated',
            'title' => $this->with,
            'message' => $this->headline().'.',
            'url' => $this->url,
        ];
    }
}
