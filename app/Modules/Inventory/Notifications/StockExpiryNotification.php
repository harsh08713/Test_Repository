<?php

namespace App\Modules\Inventory\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "A batch is about to expire" — to the people who look after stock, in-app and e-mail, once per batch. Plain values only. */
class StockExpiryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $itemName,
        public readonly string $batchNo,
        public readonly string $expiryDate,    // "31-10-2026"
        public readonly string $quantity,      // "120"
        public readonly string $value,         // "₹ 5,400.00"
        public readonly string $url,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function headline(): string
    {
        return "Batch {$this->batchNo} of {$this->itemName} expires on {$this->expiryDate}, with {$this->quantity} ({$this->value}) still in stock";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Stock about to expire: {$this->itemName}, batch {$this->batchNo}")
            ->greeting("Hello {$notifiable->name},")
            ->line($this->headline().'.')
            ->line('Use it, move it, or scrap it before it goes out of date.')
            ->action('Open the batches', url($this->url));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => 'stock_expiring', 'title' => "{$this->itemName} · batch {$this->batchNo}", 'message' => $this->headline().'.', 'url' => $this->url];
    }
}
