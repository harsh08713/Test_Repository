<?php

namespace App\Modules\Integrations\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/** "A new IndiaMART lead is waiting in the pool": in-app only (a busy account can bring dozens a day), for the people who hand leads out. */
class PoolLeadNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $provider,
        public readonly string $leadTitle,
        public readonly bool $incomplete,
        public readonly string $url,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'pool_lead',
            'title' => $this->leadTitle,
            'message' => "New {$this->provider} lead waiting to be given to someone: {$this->leadTitle}".($this->incomplete ? ' (incomplete: needs a name or mobile number)' : '').'.',
            'url' => $this->url,
        ];
    }
}
