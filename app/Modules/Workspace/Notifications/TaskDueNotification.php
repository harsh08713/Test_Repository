<?php

namespace App\Modules\Workspace\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** A task's reminder, or "this task is overdue" (`late`) — to the person it is with, in-app and e-mail, once each. Plain values only. */
class TaskDueNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly string $title,
        public readonly ?string $due,
        public readonly bool $late,
        public readonly string $url,
    ) {}

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    private function headline(): string
    {
        $when = $this->due === null ? '' : " (due {$this->due})";

        return $this->late ? "Your task is overdue: {$this->title}{$when}" : "Reminder: {$this->title}{$when}";
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(($this->late ? 'Overdue task: ' : 'Task reminder: ').$this->title)
            ->greeting("Hello {$notifiable->name},")
            ->line($this->headline().'.')
            ->action('Open the task', url($this->url));
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return ['kind' => $this->late ? 'task_overdue' : 'task_reminder', 'title' => $this->title, 'message' => $this->headline().'.', 'url' => $this->url];
    }
}
