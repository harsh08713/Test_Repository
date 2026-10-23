<?php

namespace App\Modules\Sales\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "A quotation you sent is about to expire / has expired" — to the person who owns it, in-app and e-mail, once each. Plain values only. */
class QuotationExpiryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly bool $expired,
        public readonly string $title,        // "QT/AHD/26-27/00001 · Rev 0 — Shree Ganesh Traders"
        public readonly string $validUntil,   // "31-10-2026"
        public readonly string $total,        // "₹1,18,000.00"
        public readonly string $url,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function headline(): string
    {
        return $this->expired
            ? "Quotation {$this->title} ({$this->total}) ran out on {$this->validUntil} without an answer"
            : "Quotation {$this->title} ({$this->total}) is valid only until {$this->validUntil}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(($this->expired ? 'Quotation expired: ' : 'Quotation about to expire: ').$this->title)
            ->greeting("Hello {$notifiable->name},")
            ->line($this->headline().'.')
            ->line($this->expired ? 'Make a new revision if the customer is still interested.' : 'Follow up with the customer, or make a new revision to extend it.')
            ->action('Open the quotation', url($this->url));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => $this->expired ? 'quotation_expired' : 'quotation_expiring',
            'title' => $this->title,
            'message' => $this->headline().'.',
            'url' => $this->url,
        ];
    }
}
