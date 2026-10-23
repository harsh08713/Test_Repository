<?php

namespace App\Modules\Integrations\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "The same buyer enquired again on IndiaMART": sent to the owner of the open lead that already has their number. Plain values only, so it queues safely. */
class MarketplaceEnquiryNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $provider,
        public readonly string $leadTitle,
        public readonly string $summary,
        public readonly string $url,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("{$this->provider}: another enquiry on {$this->leadTitle}")
            ->greeting("Hello {$notifiable->name},")
            ->line("A lead of yours came back through {$this->provider}: {$this->leadTitle}.")
            ->line($this->summary)
            ->action('Open the lead', url($this->url));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'marketplace_enquiry',
            'title' => $this->leadTitle,
            'message' => "Another {$this->provider} enquiry: {$this->summary}",
            'url' => $this->url,
        ];
    }
}
