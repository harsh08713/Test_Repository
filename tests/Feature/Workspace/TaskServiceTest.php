<?php

use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Workspace\Console\SendTaskReminders;
use App\Modules\Workspace\Models\Task;
use App\Modules\Workspace\Models\TaskAssignment;
use App\Modules\Workspace\Notifications\TaskAssignedNotification;
use App\Modules\Workspace\Notifications\TaskDueNotification;
use App\Modules\Workspace\Services\TaskService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
 * Tasks through their one door: what a task may be about and who it may be given to, its life (start, finish, call off, hand over), the next one of
 * a repeating task, the history of who had it, and the reminders that go out once.
 */

beforeEach(function () {
    Carbon::setTestNow('2026-10-12 09:30:00');
    Notification::fake();

    $this->acme = makeCompany('ACME', ['state_code' => '24']);
    $this->globex = makeCompany('GLOBEX');
    seedDefaults($this->acme);
    seedDefaults($this->globex);
    $this->ahd = makeLocation($this->acme, 'AHD', ['state_code' => '24']);
    $this->gx = makeLocation($this->globex, 'GX', ['state_code' => '24']);

    $this->manager = makeUser(['Sales Manager'], [$this->acme]);
    $this->bde = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->bde2 = makeUser(['BDE / Field Salesperson'], [$this->acme], [$this->ahd]);
    $this->team = makeTeam($this->acme, $this->manager, [$this->bde]);

    $this->make = fn ($by, array $input = []) => inCompany($this->acme, fn () => app(TaskService::class)->create(['title' => 'Call the customer', ...$input], $by));
    $this->svc = fn (callable $f) => inCompany($this->acme, fn () => $f(app(TaskService::class)));
});

it('makes a task for the person themselves, with the first line of its history', function () {
    $task = ($this->make)($this->bde, ['description' => 'About the quote', 'priority' => 'high', 'due_at' => '2026-10-14T11:00']);

    expect($task->title)->toBe('Call the customer')->and($task->status->value)->toBe('open')->and($task->priority->value)->toBe('high')
        ->and($task->assigned_to)->toBe($this->bde->id)->and($task->created_by)->toBe($this->bde->id)->and($task->due_at->toDateTimeString())->toBe('2026-10-14 11:00:00')
        ->and($task->company_id)->toBe($this->acme->id);

    $history = TaskAssignment::query()->where('task_id', $task->id)->get();
    expect($history)->toHaveCount(1)->and($history[0]->from_user_id)->toBeNull()->and($history[0]->to_user_id)->toBe($this->bde->id);

    Notification::assertNothingSent();   // nobody is told about a task they made for themselves
});

it('lets a manager give a task to someone on their team, and tells them', function () {
    $task = ($this->make)($this->manager, ['assigned_to' => (string) $this->bde->id, 'due_at' => '2026-10-15T10:00']);

    expect($task->assigned_to)->toBe($this->bde->id)->and($task->created_by)->toBe($this->manager->id);
    Notification::assertSentTo($this->bde, TaskAssignedNotification::class, fn ($n) => $n->by === $this->manager->name);
});

it('refuses to give a task to someone outside the person’s reach, or at all without the right to assign', function () {
    expect(fn () => ($this->make)($this->bde, ['assigned_to' => (string) $this->bde2->id]))->toThrow(ValidationException::class, 'Choose yourself')
        ->and(fn () => ($this->make)($this->manager, ['assigned_to' => (string) $this->bde2->id]))->toThrow(ValidationException::class)   // not on the manager’s team
        ->and(fn () => ($this->make)($this->bde, ['assigned_to' => '99999']))->toThrow(ValidationException::class)
        ->and(fn () => ($this->make)($this->bde, ['assigned_to' => 'abc']))->toThrow(ValidationException::class)
        ->and(Task::query()->count())->toBe(0);

    // a person without `task.assign` may only keep it, even if somebody would otherwise be in reach
    $plain = makeUser(['Tele-caller'], [$this->acme]);
    expect(fn () => ($this->make)($plain, ['assigned_to' => (string) $this->bde->id]))->toThrow(ValidationException::class);
});

it('checks the title, the dates and the repeat', function () {
    $bad = fn (array $input) => collect([$input])->map(function ($i) {
        try {
            ($this->make)($this->bde, $i);
        } catch (ValidationException $e) {
            return array_keys($e->errors());
        }

        return [];
    })->first();

    expect($bad(['title' => '  ']))->toBe(['title'])
        ->and($bad(['title' => str_repeat('x', 201)]))->toBe(['title'])
        ->and($bad(['priority' => 'whenever']))->toBe(['priority'])
        ->and($bad(['due_at' => 'tomorrow-ish']))->toBe(['due_at'])
        ->and($bad(['due_at' => '2026-10-14T11:00', 'remind_at' => '2026-10-15T09:00']))->toBe(['remind_at'])
        ->and($bad(['recurrence' => 'weekly']))->toBe(['recurrence'])
        ->and($bad(['recurrence' => 'weekly', 'due_at' => '2026-10-14T11:00', 'recurrence_until' => '2026-10-01']))->toBe(['recurrence_until'])
        ->and($bad(['recurrence' => 'fortnightly', 'due_at' => '2026-10-14T11:00']))->toBe(['recurrence'])
        ->and($bad(['due_at' => '2026-02-31T10:00']))->toBe(['due_at']);
});

it('lets a task be about a record the person may open, and nothing else', function () {
    $own = makeLead($this->acme, $this->bde, $this->ahd, ['name' => 'Alpha Works']);
    $theirs = makeLead($this->acme, $this->bde2, $this->ahd, ['name' => 'Beta Works']);
    $foreign = makeLead($this->globex, makeUser(['Company Admin'], [$this->globex]), $this->gx, ['name' => 'Foreign Works']);

    $task = ($this->make)($this->bde, ['subject_type' => 'lead', 'subject_id' => (string) $own->id]);
    expect($task->subject_type)->toBe('lead')->and($task->subject_id)->toBe($own->id)->and($task->location_id)->toBe($this->ahd->id)
        ->and(app(TaskService::class)->about($task->fresh())['label'])->toContain('Alpha Works');

    foreach ([['lead', $theirs->id], ['lead', $foreign->id], ['lead', 999999], ['user', $this->bde->id], ['task', $task->id], ['lead', 'x1']] as [$type, $id]) {
        expect(fn () => ($this->make)($this->bde, ['subject_type' => $type, 'subject_id' => (string) $id]))->toThrow(ValidationException::class, 'not available');
    }

    // the manager reaches their team’s lead, not the colleague outside it
    expect(($this->make)($this->manager, ['subject_type' => 'lead', 'subject_id' => (string) $own->id])->subject_id)->toBe($own->id);
    expect(fn () => ($this->make)($this->manager, ['subject_type' => 'lead', 'subject_id' => (string) $theirs->id]))->toThrow(ValidationException::class);
});

it('starts, finishes and calls off a task, and a finished task never changes', function () {
    $task = ($this->make)($this->bde, ['due_at' => '2026-10-14T11:00']);

    $started = ($this->svc)(fn ($s) => $s->start($task, $this->bde));
    expect($started->status->value)->toBe('in_progress');

    $done = ($this->svc)(fn ($s) => $s->complete($started, 'Spoke to the purchase head.', $this->bde));
    expect($done->status->value)->toBe('done')->and($done->completion_note)->toBe('Spoke to the purchase head.')->and($done->completed_by)->toBe($this->bde->id)
        ->and($done->completed_at->toDateTimeString())->toBe('2026-10-12 09:30:00');

    foreach ([fn ($s) => $s->start($done, $this->bde), fn ($s) => $s->complete($done, null, $this->bde), fn ($s) => $s->cancel($done, 'No longer needed', $this->bde),
        fn ($s) => $s->update($done, ['title' => 'Changed'], $this->bde), fn ($s) => $s->reassign($done, $this->bde->id, null, $this->bde)] as $change) {
        expect(fn () => ($this->svc)($change))->toThrow(FieldRuleException::class, 'no longer changes');
    }

    $other = ($this->make)($this->bde);
    expect(fn () => ($this->svc)(fn ($s) => $s->cancel($other, '  ', $this->bde)))->toThrow(ValidationException::class, 'Say why');
    $cancelled = ($this->svc)(fn ($s) => $s->cancel($other, 'Customer cancelled the visit', $this->bde));
    expect($cancelled->status->value)->toBe('cancelled')->and($cancelled->cancel_reason)->toBe('Customer cancelled the visit');
});

it('makes the next task when a repeating one is finished, keeping the reminder’s distance from the deadline', function () {
    $task = ($this->make)($this->bde, [
        'title' => 'Weekly review', 'due_at' => '2026-10-14T10:00', 'remind_at' => '2026-10-14T09:00', 'recurrence' => 'weekly', 'recurrence_until' => '2026-10-30',
    ]);

    ($this->svc)(fn ($s) => $s->complete($task, null, $this->bde));
    $next = Task::query()->where('previous_id', $task->id)->sole();

    expect($next->title)->toBe('Weekly review')->and($next->status->value)->toBe('open')->and($next->due_at->toDateTimeString())->toBe('2026-10-21 10:00:00')
        ->and($next->remind_at->toDateTimeString())->toBe('2026-10-21 09:00:00')->and($next->assigned_to)->toBe($this->bde->id)->and($next->recurrence->value)->toBe('weekly')
        ->and(TaskAssignment::query()->where('task_id', $next->id)->count())->toBe(1);

    // and the one after, until the last date is passed
    ($this->svc)(fn ($s) => $s->complete($next, null, $this->bde));
    $third = Task::query()->where('previous_id', $next->id)->sole();
    expect($third->due_at->toDateString())->toBe('2026-10-28');

    ($this->svc)(fn ($s) => $s->complete($third, null, $this->bde));
    expect(Task::query()->where('previous_id', $third->id)->exists())->toBeFalse();   // 4 Nov is after the last date
});

it('moves a repeating task that was finished late to the first date that is still ahead, and a month never overflows', function () {
    $late = ($this->make)($this->bde, ['due_at' => '2026-10-14T10:00', 'recurrence' => 'daily']);
    Carbon::setTestNow('2026-10-20 09:00:00');
    ($this->svc)(fn ($s) => $s->complete($late, null, $this->bde));
    expect(Task::query()->where('previous_id', $late->id)->sole()->due_at->toDateTimeString())->toBe('2026-10-20 10:00:00');   // today at 10:00 is still ahead of 09:00

    Carbon::setTestNow('2026-10-12 09:30:00');
    $monthly = ($this->make)($this->bde, ['title' => 'Month end', 'due_at' => '2027-01-31T10:00', 'recurrence' => 'monthly']);
    ($this->svc)(fn ($s) => $s->complete($monthly, null, $this->bde));
    expect(Task::query()->where('previous_id', $monthly->id)->sole()->due_at->toDateString())->toBe('2027-02-28');
});

it('hands a task over with a reason, keeps every giving, and re-arms the reminders', function () {
    $task = ($this->make)($this->manager, ['assigned_to' => (string) $this->bde->id, 'due_at' => '2026-10-14T10:00', 'remind_at' => '2026-10-12T09:00']);
    Task::query()->whereKey($task->id)->update(['reminder_sent_at' => now(), 'overdue_notified_at' => now()]);
    Notification::fake();

    $moved = ($this->svc)(fn ($s) => $s->reassign($task->fresh(), $this->manager->id, 'I will do it myself', $this->manager));

    expect($moved->assigned_to)->toBe($this->manager->id)->and($moved->reminder_sent_at)->toBeNull()->and($moved->overdue_notified_at)->toBeNull();

    $rows = TaskAssignment::query()->where('task_id', $task->id)->orderBy('id')->get();
    expect($rows)->toHaveCount(2)->and($rows[1]->from_user_id)->toBe($this->bde->id)->and($rows[1]->to_user_id)->toBe($this->manager->id)->and($rows[1]->reason)->toBe('I will do it myself')
        ->and($rows[1]->actor_id)->toBe($this->manager->id);

    expect(fn () => ($this->svc)(fn ($s) => $s->reassign($moved, $this->manager->id, null, $this->manager)))->toThrow(FieldRuleException::class, 'already with')
        ->and(fn () => ($this->svc)(fn ($s) => $s->reassign($moved, $this->bde2->id, null, $this->manager)))->toThrow(ValidationException::class);

    expect(fn () => $rows[0]->forceFill(['reason' => 'edited'])->save())->toThrow(LogicException::class, 'append-only')
        ->and(fn () => $rows[0]->delete())->toThrow(LogicException::class, 'append-only');
});

it('moving the deadline or the reminder re-arms what was already sent', function () {
    $task = ($this->make)($this->bde, ['due_at' => '2026-10-12T08:00', 'remind_at' => '2026-10-12T07:00']);
    Task::query()->whereKey($task->id)->update(['reminder_sent_at' => now(), 'overdue_notified_at' => now()]);

    $same = ($this->svc)(fn ($s) => $s->update($task->fresh(), ['title' => 'Renamed', 'due_at' => '2026-10-12T08:00', 'remind_at' => '2026-10-12T07:00'], $this->bde));
    expect($same->title)->toBe('Renamed')->and($same->reminder_sent_at)->not->toBeNull()->and($same->overdue_notified_at)->not->toBeNull();

    $moved = ($this->svc)(fn ($s) => $s->update($same, ['title' => 'Renamed', 'due_at' => '2026-10-13T08:00', 'remind_at' => '2026-10-13T07:00'], $this->bde));
    expect($moved->reminder_sent_at)->toBeNull()->and($moved->overdue_notified_at)->toBeNull();
});

it('reminds a person once, and announces an overdue task once, and never from the distant past', function () {
    $due = ($this->make)($this->bde, ['title' => 'Due soon', 'due_at' => '2026-10-12T09:00', 'remind_at' => '2026-10-12T08:30']);
    $stale = ($this->make)($this->bde, ['title' => 'Long forgotten', 'due_at' => '2026-10-01T09:00', 'remind_at' => '2026-10-01T08:00']);
    $later = ($this->make)($this->bde, ['title' => 'Not yet', 'due_at' => '2026-10-20T09:00', 'remind_at' => '2026-10-20T08:00']);
    $gone = ($this->make)($this->bde, ['title' => 'Done already', 'due_at' => '2026-10-12T09:00', 'remind_at' => '2026-10-12T08:30']);
    ($this->svc)(fn ($s) => $s->complete($gone, null, $this->bde));
    Notification::fake();

    Artisan::call(SendTaskReminders::class);
    Artisan::call(SendTaskReminders::class);   // a second run in the same minutes tells nobody again

    Notification::assertSentToTimes($this->bde, TaskDueNotification::class, 2);   // the reminder and the overdue notice of the one task
    Notification::assertSentTo($this->bde, TaskDueNotification::class, fn ($n) => $n->title === 'Due soon' && $n->late === false);
    Notification::assertSentTo($this->bde, TaskDueNotification::class, fn ($n) => $n->title === 'Due soon' && $n->late === true);
    Notification::assertNotSentTo($this->bde, TaskDueNotification::class, fn ($n) => in_array($n->title, ['Long forgotten', 'Not yet', 'Done already'], true));

    expect($due->fresh()->reminder_sent_at)->not->toBeNull()->and($due->fresh()->overdue_notified_at)->not->toBeNull()
        ->and($later->fresh()->reminder_sent_at)->toBeNull()->and($stale->fresh()->reminder_sent_at)->toBeNull();
});
