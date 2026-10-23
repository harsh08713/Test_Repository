<?php

namespace App\Modules\Shared\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the person who submitted a document that it was approved or rejected. */
class DocumentDecisionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $title,
        public readonly ?string $url,
        public readonly bool $approved,
        public readonly string $decidedBy,
        public readonly ?string $comment = null,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $verb = $this->approved ? 'approved' : 'rejected';

        $mail = (new MailMessage)
            ->subject(ucfirst($verb).": {$this->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("{$this->decidedBy} {$verb} {$this->title}.");

        if ($this->comment) {
            $mail->line("Comment: {$this->comment}");
        }

        return $this->url ? $mail->action('Open document', url($this->url)) : $mail;
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        $verb = $this->approved ? 'approved' : 'rejected';

        return [
            'kind' => $this->approved ? 'document_approved' : 'document_rejected',
            'title' => $this->title,
            'message' => "{$this->decidedBy} {$verb} {$this->title}".($this->comment ? " — “{$this->comment}”" : '.'),
            'url' => $this->url,
        ];
    }
}
