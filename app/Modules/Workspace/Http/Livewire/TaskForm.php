<?php

namespace App\Modules\Workspace\Http\Livewire;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Workspace\Enums\Recurrence;
use App\Modules\Workspace\Enums\TaskPriority;
use App\Modules\Workspace\Models\Task;
use App\Modules\Workspace\Services\TaskAccess;
use App\Modules\Workspace\Services\TaskService;
use App\Modules\Workspace\Services\TaskSubjects;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Make or change a task. A new task can start from a record (`?type=lead&id=12`, the link on the lead, deal, quotation, order and customer
 * pages); the service checks that the person may open that record. A task that is done or called off cannot be opened for editing.
 */
#[Title('Task')]
class TaskForm extends Component
{
    /** @var array<string, string> */
    public array $t = [
        'title' => '', 'description' => '', 'priority' => 'normal', 'due_at' => '', 'remind_at' => '', 'recurrence' => 'none', 'recurrence_until' => '', 'assigned_to' => '',
    ];

    #[Url(as: 'type')]
    public string $subjectType = '';

    #[Url(as: 'id')]
    public string $subjectId = '';

    #[Locked]
    public int $taskId = 0;

    /** What the person types to find the record the task is about. */
    public string $subjectSearch = '';

    public function mount(?int $editing = null): void
    {
        $user = $this->actor();

        if ($editing !== null) {
            $task = Task::query()->findOrFail($editing);
            Gate::authorize('update', $task);

            $this->taskId = $task->getKey();
            $this->t = [
                'title' => $task->title, 'description' => (string) $task->description, 'priority' => $task->priority->value,
                'due_at' => $task->due_at?->format('Y-m-d\TH:i') ?? '', 'remind_at' => $task->remind_at?->format('Y-m-d\TH:i') ?? '',
                'recurrence' => $task->recurrence->value, 'recurrence_until' => $task->recurrence_until?->toDateString() ?? '', 'assigned_to' => (string) $task->assigned_to,
            ];

            return;
        }

        Gate::authorize('create', Task::class);
        $this->t['assigned_to'] = (string) $user->getKey();
    }

    /** Choose a record from the picker; the service checks the choice again when the task is saved. */
    public function pickSubject(string $type, int $id): void
    {
        abort_unless($this->taskId === 0 && in_array($type, TaskService::SUBJECTS, true), 404);

        $this->subjectType = $type;
        $this->subjectId = (string) $id;
        $this->subjectSearch = '';
    }

    public function clearSubject(): void
    {
        $this->subjectType = '';
        $this->subjectId = '';
    }

    public function save(TaskService $tasks): mixed
    {
        $user = $this->actor();
        $this->resetErrorBag();

        try {
            if ($this->taskId !== 0) {
                $task = Task::query()->findOrFail($this->taskId);
                Gate::authorize('update', $task);
                $saved = $tasks->update($task, $this->t, $user);
            } else {
                Gate::authorize('create', Task::class);
                $saved = $tasks->create([...$this->t, 'subject_type' => $this->subjectType, 'subject_id' => $this->subjectId], $user);
            }
        } catch (FieldRuleException $e) {
            $this->addError('form', $e->getMessage());

            return null;
        }

        session()->flash('status', $this->taskId !== 0 ? 'Task saved.' : 'Task made.');

        return $this->redirectRoute('workspace.tasks.show', $saved, navigate: true);
    }

    public function render(TaskAccess $access, TaskService $tasks, TaskSubjects $subjects): View
    {
        $user = $this->actor();
        $about = null;

        if ($this->taskId === 0 && $this->subjectType !== '' && ctype_digit($this->subjectId)) {
            $probe = new Task;
            $probe->forceFill(['subject_type' => $this->subjectType, 'subject_id' => (int) $this->subjectId]);
            $about = $user->can('task.view') ? $tasks->about($probe, $user) : null;
        } elseif ($this->taskId !== 0) {
            $about = $tasks->about(Task::query()->findOrFail($this->taskId), $user);
        }

        return view('workspace.tasks.form', [
            'editing' => $this->taskId !== 0,
            'people' => $user->can('task.assign') ? $access->assignable($user) : [(int) $user->getKey() => $user->name],
            'priorities' => TaskPriority::options(),
            'recurrences' => Recurrence::options(),
            'about' => $about,
            'found' => $this->taskId === 0 && $about === null ? $subjects->search($user, $this->subjectSearch) : [],
        ]);
    }

    private function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
