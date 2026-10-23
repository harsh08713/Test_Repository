<?php

namespace App\Modules\Workspace\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** "A task has been given to you" — in-app and e-mail. Plain values only. */
class TaskAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $title,
        public readonly string $by,
        public readonly ?string $due,
        public readonly string $url,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function headline(): string
    {
        return "{$this->by} gave you a task: {$this->title}".($this->due === null ? '' : " (due {$this->due})");
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("New task: {$this->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line($this->headline().'.')
            ->action('Open the task', url($this->url));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => 'task_assigned', 'title' => $this->title, 'message' => $this->headline().'.', 'url' => $this->url];
    }
}
