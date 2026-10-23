<?php

namespace App\Modules\Workspace\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "An SLA has run out" — to the person the thing is with, and (escalation) to their manager. In-app and e-mail, once each. Plain values only. */
class SlaBreachedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $kind,      // "First response"
        public readonly string $what,      // "LD/AHD/26-27/00012 — Shree Ganesh Traders"
        public readonly string $due,       // "13-10-2026 15:30"
        public readonly string $url,
        public readonly ?string $person = null,   // set for the manager: whose it is
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function headline(): string
    {
        $head = "{$this->kind} time ran out for {$this->what} (it was due {$this->due})";

        return $this->person === null ? $head : "{$head} — with {$this->person}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(($this->person === null ? 'SLA missed: ' : 'SLA missed on your team: ').$this->what)
            ->greeting("Hello {$notifiable->name},")
            ->line($this->headline().'.')
            ->action('Open it', url($this->url));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->person === null ? 'sla_breached' : 'sla_escalated', 'title' => $this->what, 'message' => $this->headline().'.', 'url' => $this->url];
    }
}
