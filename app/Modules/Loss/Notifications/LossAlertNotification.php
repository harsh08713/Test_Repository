<?php

namespace App\Modules\Loss\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * A loss that needs someone now: a safety, quality, compliance or critical incident the moment it is reported, an incident handed to its
 * investigator or owner, or a corrective action past its due date. In-app and e-mail, plain values only.
 */
class LossAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $kind,       // loss_escalated | loss_assigned | capa_overdue | radar_high | savings_submitted | savings_recurred
        public readonly string $title,      // "LI/AHD/26-27/00004 — Line stoppage on press 3"
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
        $subject = match ($this->kind) {
            'loss_escalated' => 'Loss needs attention now: ',
            'capa_overdue' => 'Corrective action overdue: ',
            'radar_high' => 'Possible loss found: ',
            'savings_submitted' => 'Saving waiting for Finance: ',
            'savings_recurred' => 'A verified saving has recurred: ',
            default => 'Loss incident for you: ',
        };

        return (new MailMessage)
            ->subject($subject.$this->title)
            ->greeting("Hello {$notifiable->name},")
            ->line($this->message)
            ->action('Open it', url($this->url));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->kind, 'title' => $this->title, 'message' => $this->message, 'url' => $this->url];
    }
}
