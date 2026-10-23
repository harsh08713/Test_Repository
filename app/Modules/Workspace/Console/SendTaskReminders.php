<?php

namespace App\Modules\Workspace\Console;

use App\Models\User;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Services\Heartbeat;
use App\Modules\Shared\Support\Format;
use App\Modules\Workspace\Models\Task;
use App\Modules\Workspace\Notifications\TaskDueNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Reminds people of tasks that have reached their reminder time, and tells them once when a task has gone past its deadline — in-app and by
 * e-mail. Run by the scheduler every five minutes for every company. Each is *claimed* with a single conditional UPDATE before anything is
 * sent, so overlapping or retried runs never tell anybody twice; moving a task's reminder or deadline re-arms it. Tasks more than a day past their
 * reminder (or a week past their deadline) are left alone: after an outage, a flood of stale messages helps nobody.
 */
class SendTaskReminders extends Command
{
    protected $signature = 'tasks:remind';

    protected $description = 'Remind people of tasks that are due soon and tell them about tasks that are overdue, once each';

    private const BATCH = 2000;

    public function handle(): int
    {
        $now = now();

        $this->info('Task reminders sent: '.$this->send($now, 'reminder_sent_at', false));
        $this->info('Overdue tasks announced: '.$this->send($now, 'overdue_notified_at', true));
        app(Heartbeat::class)->beat('tasks:remind');

        return self::SUCCESS;
    }

    private function send(Carbon $now, string $column, bool $late): int
    {
        $sent = 0;
        $when = $late ? 'due_at' : 'remind_at';

        $tasks = Task::withoutGlobalScopes(Tenancy::SCOPES)->whereIn('status', ['open', 'in_progress'])->whereNull($column)
            ->where($when, '<=', $now)->where($when, '>=', $now->copy()->subDays($late ? 7 : 1))
            ->orderBy($when)->limit(self::BATCH)->get();

        foreach ($tasks as $task) {
            // claim it first: whoever wins this UPDATE sends the message, and nobody else does
            $claimed = Task::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($task->getKey())->whereNull($column)->whereIn('status', ['open', 'in_progress'])->update([$column => $now]);

            if ($claimed !== 1) {
                continue;
            }

            $person = User::query()->where('is_active', true)->find($task->assigned_to);

            if ($person === null) {
                continue;
            }

            $person->notify(new TaskDueNotification(
                title: $task->title,
                due: $task->due_at === null ? null : Format::dateTime($task->due_at),
                late: $late,
                url: route('workspace.tasks.show', $task->getKey(), false),
            ));

            $sent++;
        }

        return $sent;
    }
}
