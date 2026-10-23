<?php

namespace App\Modules\Workspace\Services;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\CRM\Services\FollowupService;
use App\Modules\Shared\Tenancy\TenantContext;
use App\Modules\Workspace\Enums\Recurrence;
use App\Modules\Workspace\Enums\TaskPriority;
use App\Modules\Workspace\Enums\TaskStatus;
use App\Modules\Workspace\Models\Task;
use App\Modules\Workspace\Models\TaskAssignment;
use App\Modules\Workspace\Notifications\TaskAssignedNotification;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Task data has one door. The form and the dashboard send it the same input; the service checks it, takes care of who the task is with and
 * its history, and makes the next one of a recurring task. It does not check the permission to use a screen — the policy does that — but it
 * does check what the data allows: who a task may be given to, which records it may be about, and that a finished task stays finished.
 *
 *  - **Create** (`create`): a title, optionally a deadline, a reminder, a priority, a repeat and a record it is about.
 *  - **Edit** (`update`), **start** (`start`), **finish** (`complete`, with a note — a repeating task then makes its next one) and **call off**
 *    (`cancel`, with a reason). A task that is done or called off is a record and never changes.
 *  - **Hand over** (`reassign`): every giving is a row of the assignment history.
 */
final class TaskService
{
    /** The records a task can be about (their morph aliases). */
    public const SUBJECTS = ['lead', 'deal', 'quotation', 'sales_order', 'ledger'];

    public function __construct(private readonly TaskAccess $access, private readonly FollowupService $followups, private readonly TenantContext $tenant) {}

    /**
     * @param  array<string, mixed>  $input  title, description, priority, due_at, remind_at, recurrence, recurrence_until, assigned_to, subject_type, subject_id
     *
     * @throws ValidationException
     */
    public function create(array $input, User $actor): Task
    {
        $data = $this->validated($input, $actor);
        $assigneeId = $this->assignee($input['assigned_to'] ?? null, $actor);
        $subject = $this->subject($input['subject_type'] ?? null, $input['subject_id'] ?? null, $actor);

        $task = DB::transaction(function () use ($data, $assigneeId, $subject, $actor) {
            $task = new Task;
            $task->fill($data);
            $task->forceFill([
                'company_id' => $this->tenant->activeCompanyId(), 'assigned_to' => $assigneeId, 'status' => TaskStatus::Open->value,
                'subject_type' => $subject?->getMorphClass(), 'subject_id' => $subject?->getKey(), 'location_id' => $subject?->getAttribute('location_id'),
                'created_by' => $actor->getKey(), 'updated_by' => $actor->getKey(),
            ])->save();

            $this->recordAssignment($task, null, $assigneeId, null, $actor);

            return $task->refresh();
        });

        $this->tellAssignee($task, $actor);

        return $task;
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function update(Task $task, array $input, User $actor): Task
    {
        $data = $this->validated($input, $actor);

        return DB::transaction(function () use ($task, $data, $actor) {
            $fresh = $this->live($task);
            $dueMoved = ($data['due_at'] ?? null) !== optional($fresh->due_at)->format('Y-m-d H:i:s');
            $remindMoved = ($data['remind_at'] ?? null) !== optional($fresh->remind_at)->format('Y-m-d H:i:s');

            $fresh->fill($data);
            $fresh->forceFill(['updated_by' => $actor->getKey()]);

            // moving the deadline or the reminder re-arms what the scheduler has already told the person
            $remindMoved && $fresh->forceFill(['reminder_sent_at' => null]);
            $dueMoved && $fresh->forceFill(['overdue_notified_at' => null]);
            $fresh->save();

            return $fresh->refresh();
        });
    }

    /** To do → in progress. */
    public function start(Task $task, User $actor): Task
    {
        return DB::transaction(function () use ($task, $actor) {
            $fresh = $this->live($task);
            $fresh->forceFill(['status' => TaskStatus::InProgress->value, 'updated_by' => $actor->getKey()])->save();

            return $fresh->refresh();
        });
    }

    /**
     * Finish a task, with a note on what was done. A repeating task makes the next one (see `next()`).
     *
     * @throws FieldRuleException
     * @throws ValidationException
     */
    public function complete(Task $task, ?string $note, User $actor): Task
    {
        $note = $this->text($note);

        Validator::make(['note' => $note], ['note' => ['nullable', 'string', 'max:2000']])->validate();

        return DB::transaction(function () use ($task, $note, $actor) {
            $fresh = $this->live($task);
            $fresh->forceFill([
                'status' => TaskStatus::Done->value, 'completed_at' => now(), 'completed_by' => $actor->getKey(), 'completion_note' => $note, 'updated_by' => $actor->getKey(),
            ])->save();

            $this->next($fresh, $actor);

            return $fresh->refresh();
        });
    }

    /**
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function cancel(Task $task, string $reason, User $actor): Task
    {
        $reason = (string) $this->text($reason);

        Validator::make(['reason' => $reason], ['reason' => ['required', 'string', 'max:500']], ['reason.required' => 'Say why the task is being called off.'])->validate();

        return DB::transaction(function () use ($task, $reason, $actor) {
            $fresh = $this->live($task);
            $fresh->forceFill(['status' => TaskStatus::Cancelled->value, 'cancel_reason' => $reason, 'updated_by' => $actor->getKey()])->save();

            return $fresh->refresh();
        });
    }

    /**
     * Give the task to somebody else. The reminders are re-armed for the new person.
     *
     * @throws ValidationException
     * @throws FieldRuleException
     */
    public function reassign(Task $task, int $toUserId, ?string $reason, User $actor): Task
    {
        $toUserId = $this->assignee($toUserId, $actor);
        $reason = $this->text($reason);

        Validator::make(['reason' => $reason], ['reason' => ['nullable', 'string', 'max:500']])->validate();

        $fresh = DB::transaction(function () use ($task, $toUserId, $reason, $actor) {
            $fresh = $this->live($task);

            if ((int) $fresh->assigned_to === $toUserId) {
                throw new FieldRuleException('The task is already with that person.');
            }

            $from = (int) $fresh->assigned_to;
            $fresh->forceFill(['assigned_to' => $toUserId, 'reminder_sent_at' => null, 'overdue_notified_at' => null, 'updated_by' => $actor->getKey()])->save();
            $this->recordAssignment($fresh, $from, $toUserId, $reason, $actor);

            return $fresh->refresh();
        });

        $this->tellAssignee($fresh, $actor);

        return $fresh;
    }

    /**
     * What a task is about, for people: its label and where to open it. Null when it is about nothing, or the record is no longer available.
     *
     * @return array{label: string, url: string|null, type: string}|null
     */
    public function about(Task $task, ?User $viewer = null): ?array
    {
        if ($task->subject_type === null || $task->subject_id === null) {
            return null;
        }

        $subject = $task->relationLoaded('subject') ? $task->subject : $task->subject()->first();

        if (! $subject instanceof Model) {
            return null;
        }

        // the label of a record is itself information: show it only to someone who may open the record
        if ($viewer !== null && ! Gate::forUser($viewer)->allows('view', $subject)) {
            return ['type' => 'Record', 'label' => 'one you cannot open', 'url' => null];
        }

        return match ($task->subject_type) {
            'lead' => ['type' => 'Lead', 'label' => trim($subject->getAttribute('lead_number').' · '.$subject->getAttribute('name')), 'url' => route('crm.leads.show', $subject->getKey(), false)],
            'deal' => ['type' => 'Deal', 'label' => trim($subject->getAttribute('deal_number').' · '.$subject->getAttribute('party_name')), 'url' => route('crm.deals.show', $subject->getKey(), false)],
            'quotation' => ['type' => 'Quotation', 'label' => trim($subject->getAttribute('quotation_number').' · '.$subject->getAttribute('party_name')), 'url' => route('sales.quotations.show', $subject->getKey(), false)],
            'sales_order' => ['type' => 'Sales order', 'label' => trim($subject->getAttribute('so_number').' · '.$subject->getAttribute('party_name')), 'url' => route('sales.orders.show', $subject->getKey(), false)],
            'ledger' => ['type' => 'Customer', 'label' => trim($subject->getAttribute('code').' · '.$subject->getAttribute('name')), 'url' => route('crm.customers.show', $subject->getKey(), false)],
            default => null,
        };
    }

    // ------------------------------------------------------------------------------------------------------------------------------

    /**
     * The next occurrence of a repeating task, if it repeats and is not past its end date: one repeat after the deadline that has just been
     * served (and, if that is already in the past, the first one that is not).
     */
    private function next(Task $done, User $actor): ?Task
    {
        if ($done->recurrence === Recurrence::None || $done->due_at === null) {
            return null;
        }

        $due = $done->due_at->copy();

        for ($i = 0; $i < 400; $i++) {
            $due = $done->recurrence->next($due) ?? $due;

            if ($due->isFuture()) {
                break;
            }
        }

        if ($done->recurrence_until !== null && $due->toDateString() > $done->recurrence_until->toDateString()) {
            return null;
        }

        $offset = $done->remind_at === null ? null : $done->due_at->diffInSeconds($done->remind_at, false);

        $next = new Task;
        $next->forceFill([
            'company_id' => $done->company_id, 'location_id' => $done->location_id, 'title' => $done->title, 'description' => $done->description,
            'priority' => $done->priority->value, 'status' => TaskStatus::Open->value, 'due_at' => $due,
            'remind_at' => $offset === null ? null : $due->copy()->addSeconds((int) $offset),
            'assigned_to' => $done->assigned_to, 'subject_type' => $done->subject_type, 'subject_id' => $done->subject_id,
            'recurrence' => $done->recurrence->value, 'recurrence_until' => $done->recurrence_until, 'previous_id' => $done->getKey(),
            'created_by' => $done->created_by, 'updated_by' => $actor->getKey(),
        ])->save();

        $this->recordAssignment($next, null, (int) $done->assigned_to, 'Repeat of an earlier task', $actor);

        return $next;
    }

    /** Re-read the task under a lock and refuse to change one that is finished. */
    private function live(Task $task): Task
    {
        $fresh = Task::query()->whereKey($task->getKey())->lockForUpdate()->firstOrFail();

        if (! $fresh->isLive()) {
            throw new FieldRuleException('This task is '.strtolower($fresh->status->label()).' and no longer changes.');
        }

        return $fresh;
    }

    private function recordAssignment(Task $task, ?int $from, int $to, ?string $reason, User $actor): void
    {
        $row = new TaskAssignment;
        $row->forceFill([
            'company_id' => $task->company_id, 'task_id' => $task->getKey(), 'from_user_id' => $from, 'to_user_id' => $to,
            'reason' => $reason, 'actor_id' => $actor->getKey(),
        ])->save();
    }

    private function tellAssignee(Task $task, User $actor): void
    {
        if ((int) $task->assigned_to === (int) $actor->getKey()) {
            return;
        }

        User::query()->where('is_active', true)->find($task->assigned_to)?->notify(new TaskAssignedNotification(
            title: $task->title, by: $actor->name, due: $task->due_at?->format('d-m-Y H:i'), url: route('workspace.tasks.show', $task->getKey(), false),
        ));
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed> the fillable fields, checked
     *
     * @throws ValidationException
     */
    private function validated(array $input, User $actor): array
    {
        $due = $this->followups->parseWhen($input['due_at'] ?? null);
        $remind = $this->followups->parseWhen($input['remind_at'] ?? null);
        $until = trim((string) ($input['recurrence_until'] ?? ''));

        $data = Validator::make([
            'title' => $this->text($input['title'] ?? null), 'description' => $this->text($input['description'] ?? null),
            'priority' => trim((string) ($input['priority'] ?? 'normal')) ?: 'normal', 'recurrence' => trim((string) ($input['recurrence'] ?? 'none')) ?: 'none',
            'due_at' => $due?->format('Y-m-d H:i:s'), 'remind_at' => $remind?->format('Y-m-d H:i:s'), 'recurrence_until' => $until === '' ? null : $until,
        ], [
            'title' => ['required', 'string', 'max:200'],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['required', Rule::in(array_keys(TaskPriority::options()))],
            'recurrence' => ['required', Rule::in(array_keys(Recurrence::options()))],
            'due_at' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'remind_at' => ['nullable', 'date_format:Y-m-d H:i:s'],
            'recurrence_until' => ['nullable', 'date_format:Y-m-d'],
        ], ['title.required' => 'Say what has to be done.'])->validate();

        if (($input['due_at'] ?? '') !== '' && $due === null) {
            throw ValidationException::withMessages(['due_at' => 'The deadline is not a date and time.']);
        }

        if (($input['remind_at'] ?? '') !== '' && $remind === null) {
            throw ValidationException::withMessages(['remind_at' => 'The reminder is not a date and time.']);
        }

        if ($remind !== null && $due !== null && $remind->gt($due)) {
            throw ValidationException::withMessages(['remind_at' => 'The reminder must come before the deadline.']);
        }

        if ($data['recurrence'] !== Recurrence::None->value && $due === null) {
            throw ValidationException::withMessages(['recurrence' => 'A task that repeats needs a deadline to repeat from.']);
        }

        if ($data['recurrence_until'] !== null && $due !== null && $data['recurrence_until'] < $due->toDateString()) {
            throw ValidationException::withMessages(['recurrence_until' => 'The last repeat cannot be before the deadline.']);
        }

        return $data;
    }

    /** @throws ValidationException */
    private function assignee(mixed $id, User $actor): int
    {
        $id = is_scalar($id) ? trim((string) $id) : '';
        $id = $id === '' ? (int) $actor->getKey() : (ctype_digit($id) ? (int) $id : 0);

        if (! array_key_exists($id, $this->access->assignable($actor))) {
            throw ValidationException::withMessages(['assigned_to' => 'Choose yourself, or someone on a team you manage.']);
        }

        if ($id !== (int) $actor->getKey() && ! $actor->can('task.assign')) {
            throw ValidationException::withMessages(['assigned_to' => 'You may give tasks to yourself only.']);
        }

        return $id;
    }

    /** The record a task is about: it must be one the person can open, in this company. @throws ValidationException */
    private function subject(mixed $type, mixed $id, User $actor): ?Model
    {
        $type = is_scalar($type) ? trim((string) $type) : '';
        $id = is_scalar($id) ? trim((string) $id) : '';

        if ($type === '' && $id === '') {
            return null;
        }

        $class = in_array($type, self::SUBJECTS, true) ? Relation::getMorphedModel($type) : null;
        $subject = $class !== null && ctype_digit($id) ? $class::query()->find((int) $id) : null;

        // the whole model, never a column list: the policy reads several of its attributes
        if (! $subject instanceof Model || ! Gate::forUser($actor)->allows('view', $subject)) {
            throw ValidationException::withMessages(['subject_id' => 'That record is not available to you.']);
        }

        return $subject;
    }

    private function text(mixed $value): ?string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text === '' ? null : $text;
    }
}
