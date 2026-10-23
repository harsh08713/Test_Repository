<?php

namespace App\Modules\Workspace\Http\Livewire;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Workspace\Models\SlaClock;
use App\Modules\Workspace\Models\Task;
use App\Modules\Workspace\Models\TaskAttachment;
use App\Modules\Workspace\Services\TaskAccess;
use App\Modules\Workspace\Services\TaskAttachmentService;
use App\Modules\Workspace\Services\TaskService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/** One task: what it is, who has it, its history, and what can be done with it while it is still to do. */
#[Title('Task')]
class TaskShow extends Component
{
    use WithFileUploads;

    /** The file being added. */
    public mixed $attachment = null;

    #[Locked]
    public int $taskId = 0;

    /** The box that is open: '' | finish | cancel | give */
    public string $dialog = '';

    public string $note = '';

    public string $reason = '';

    public string $giveTo = '';

    public function mount(Task $task): void
    {
        Gate::authorize('view', $task);

        $this->taskId = $task->getKey();
    }

    public function openDialog(string $which): void
    {
        abort_unless(in_array($which, ['finish', 'cancel', 'give'], true), 404);

        Gate::authorize($which === 'give' ? 'assign' : 'update', $this->task());

        $this->dialog = $which;
        $this->note = $this->reason = $this->giveTo = '';
        $this->resetErrorBag();
    }

    public function closeDialog(): void
    {
        $this->dialog = '';
        $this->resetErrorBag();
    }

    public function startTask(TaskService $tasks): void
    {
        $task = $this->task();
        Gate::authorize('update', $task);

        $this->run(fn () => $tasks->start($task, $this->actor()), 'Marked as in progress.');
    }

    public function finishTask(TaskService $tasks): void
    {
        $task = $this->task();
        Gate::authorize('update', $task);

        $this->run(fn () => $tasks->complete($task, $this->note, $this->actor()), 'Task done.');
    }

    public function cancelTask(TaskService $tasks): void
    {
        $task = $this->task();
        Gate::authorize('update', $task);

        $this->run(fn () => $tasks->cancel($task, $this->reason, $this->actor()), 'The task is called off.');
    }

    public function giveTask(TaskService $tasks): void
    {
        $task = $this->task();
        Gate::authorize('assign', $task);

        $this->run(fn () => $tasks->reassign($task, ctype_digit($this->giveTo) ? (int) $this->giveTo : 0, $this->reason, $this->actor()), 'The task is now with the new person.');
    }

    public function addFile(TaskAttachmentService $files): void
    {
        $task = $this->task();
        Gate::authorize('update', $task);
        $this->resetErrorBag();

        $this->validate(['attachment' => ['required', 'file', 'max:'.TaskAttachmentService::MAX_KB]], ['attachment.required' => 'Choose a file.', 'attachment.max' => 'The file is larger than '.(TaskAttachmentService::MAX_KB / 1024).' MB.']);

        try {
            $files->attach($task, $this->attachment, $this->actor());
        } catch (FieldRuleException $e) {
            $this->addError('attachment', $e->getMessage());

            return;
        } finally {
            $this->attachment = null;
        }

        session()->flash('status', 'File added.');
    }

    public function removeFile(int $id, TaskAttachmentService $files): void
    {
        $task = $this->task();
        Gate::authorize('update', $task);
        $attachment = TaskAttachment::query()->where('task_id', $task->getKey())->findOrFail($id);

        try {
            $files->remove($attachment, $this->actor());
        } catch (FieldRuleException $e) {
            $this->addError('attachment', $e->getMessage());

            return;
        }

        session()->flash('status', 'File removed.');
    }

    public function render(TaskAccess $access, TaskService $tasks): View
    {
        $task = $this->task();
        $task->load(['assignee:id,name', 'creator:id,name', 'completer:id,name', 'assignments.from:id,name', 'assignments.to:id,name', 'assignments.actor:id,name']);

        return view('workspace.tasks.show', [
            'task' => $task,
            'about' => $tasks->about($task, $this->actor()),
            'people' => array_diff_key($access->assignable($this->actor()), [(int) $task->assigned_to => true]),
            'files' => TaskAttachment::query()->where('task_id', $task->getKey())->whereNull('removed_at')->with('uploader:id,name')->orderBy('id')->get(),
            'clock' => SlaClock::query()->where('subject_type', 'task')->where('subject_id', $task->getKey())->orderByDesc('id')->first(),
        ]);
    }

    private function task(): Task
    {
        return Task::query()->findOrFail($this->taskId);
    }

    private function run(\Closure $step, string $said): void
    {
        $this->resetErrorBag();

        try {
            $step();
        } catch (FieldRuleException $e) {
            $this->addError('workflow', $e->getMessage());
            $this->dialog = '';

            return;
        }

        $this->dialog = '';
        session()->flash('status', $said);
    }

    private function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
