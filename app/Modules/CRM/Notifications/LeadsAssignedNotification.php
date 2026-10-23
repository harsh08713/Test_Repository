<?php

namespace App\Modules\CRM\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "You have been given a lead" (or several) — in-app and e-mail. Carries plain values only, so it queues safely. */
class LeadsAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $count,
        public readonly string $firstTitle,
        public readonly string $by,
        public readonly string $url,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function headline(): string
    {
        return $this->count === 1 ? "{$this->by} gave you a lead: {$this->firstTitle}" : "{$this->by} gave you {$this->count} leads, starting with {$this->firstTitle}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->count === 1 ? "New lead for you: {$this->firstTitle}" : "{$this->count} new leads for you")
            ->greeting("Hello {$notifiable->name},")
            ->line($this->headline().'.')
            ->action($this->count === 1 ? 'Open the lead' : 'Open your leads', url($this->url));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'leads_assigned',
            'title' => $this->count === 1 ? $this->firstTitle : "{$this->count} leads",
            'message' => $this->headline().'.',
            'url' => $this->url,
        ];
    }
}
