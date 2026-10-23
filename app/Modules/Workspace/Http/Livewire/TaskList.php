<?php

namespace App\Modules\Workspace\Http\Livewire;

use App\Models\User;
use App\Modules\CRM\Exceptions\FieldRuleException;
use App\Modules\Workspace\Enums\TaskPriority;
use App\Modules\Workspace\Enums\TaskStatus;
use App\Modules\Workspace\Models\Task;
use App\Modules\Workspace\Services\TaskAccess;
use App\Modules\Workspace\Services\TaskService;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The tasks a person may see, as a list, a board (to do / in progress / done) or a month calendar of deadlines. "Mine" is what is with them;
 * "Given by me" what they gave to others; "Team" what is with the people they manage (or everyone, for someone who sees everyone).
 */
#[Title('Tasks')]
class TaskList extends Component
{
    use WithPagination;

    public const PER_PAGE = 20;

    public const BOARD_LIMIT = 30;

    /** list | board | calendar */
    #[Url(as: 'view')]
    public string $mode = 'list';

    /** mine | given | team | all */
    #[Url(as: 'show')]
    public string $show = 'mine';

    /** live | overdue | done | all (the list; the board and the calendar have their own) */
    #[Url(as: 'state')]
    public string $state = 'live';

    #[Url(as: 'person')]
    public string $person = '';

    #[Url(as: 'priority')]
    public string $priority = '';

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'month')]
    public string $month = '';

    /** The task whose “finish it” box is open (0 = none). */
    public int $finishing = 0;

    public string $note = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Task::class);

        if (preg_match('/^\d{4}-\d{2}$/', $this->month) !== 1) {
            $this->month = now()->format('Y-m');
        }
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['show', 'state', 'person', 'priority', 'search', 'mode'], true)) {
            $this->resetPage();
        }
    }

    public function shiftMonth(int $by): void
    {
        $this->month = Carbon::createFromFormat('Y-m-d', $this->month.'-01')->addMonths($by > 0 ? 1 : -1)->format('Y-m');
    }

    public function startTask(int $id, TaskService $tasks): void
    {
        $task = Task::query()->findOrFail($id);
        Gate::authorize('update', $task);

        try {
            $tasks->start($task, $this->actor());
        } catch (FieldRuleException $e) {
            $this->addError('list', $e->getMessage());

            return;
        }

        session()->flash('status', 'Marked as in progress.');
    }

    public function openFinish(int $id): void
    {
        Gate::authorize('update', Task::query()->findOrFail($id));

        $this->finishing = $id;
        $this->note = '';
        $this->resetErrorBag();
    }

    public function closeFinish(): void
    {
        $this->finishing = 0;
        $this->resetErrorBag();
    }

    public function finishTask(TaskService $tasks): void
    {
        $task = Task::query()->findOrFail($this->finishing);
        Gate::authorize('update', $task);
        $this->resetErrorBag();

        try {
            $tasks->complete($task, $this->note, $this->actor());
        } catch (FieldRuleException $e) {
            $this->addError('list', $e->getMessage());
            $this->finishing = 0;

            return;
        }

        $this->finishing = 0;
        session()->flash('status', 'Task done.');
    }

    public function render(TaskAccess $access): View
    {
        $user = $this->actor();
        $tasks = app(TaskService::class);
        $data = ['people' => $access->assignable($user), 'priorities' => TaskPriority::options(), 'canCreate' => Gate::allows('create', Task::class), 'now' => now(),
            'canExport' => $user->can('task.export') && $user->can('report.export') && $user->can('report.view')];

        if ($this->mode === 'board') {
            $columns = [];

            foreach ([TaskStatus::Open, TaskStatus::InProgress, TaskStatus::Done] as $status) {
                $query = $this->base($user)->where('tasks.status', $status->value);
                $status === TaskStatus::Done && $query->where('tasks.completed_at', '>=', now()->subDays(14));

                $columns[$status->value] = [
                    'label' => $status->label(), 'count' => (clone $query)->count(),
                    'tasks' => $query->with(['assignee:id,name', 'subject'])->orderByRaw('tasks.due_at is null')->orderBy('tasks.due_at')->orderBy('tasks.id')->limit(self::BOARD_LIMIT)->get(),
                ];
            }

            return view('workspace.tasks.list', $data + ['columns' => $columns, 'about' => fn (Task $t) => $tasks->about($t, $user)]);
        }

        if ($this->mode === 'calendar') {
            $first = Carbon::createFromFormat('Y-m-d', $this->month.'-01')->startOfDay();
            $found = $this->base($user)->where('tasks.status', '<>', 'cancelled')->whereBetween('tasks.due_at', [$first, $first->copy()->endOfMonth()])
                ->orderBy('tasks.due_at')->orderBy('tasks.id')->get(['tasks.id', 'tasks.title', 'tasks.due_at', 'tasks.status', 'tasks.priority'])->groupBy(fn (Task $t) => $t->due_at->toDateString());

            return view('workspace.tasks.list', $data + ['first' => $first, 'byDay' => $found]);
        }

        $paged = $this->base($user)->with(['assignee:id,name', 'subject'])
            ->when($this->state === 'live', fn (Builder $q) => $q->whereIn('tasks.status', TaskStatus::liveValues()))
            ->when($this->state === 'overdue', fn (Builder $q) => $q->whereIn('tasks.status', TaskStatus::liveValues())->where('tasks.due_at', '<', now()))
            ->when($this->state === 'done', fn (Builder $q) => $q->where('tasks.status', 'done'))
            ->orderByRaw("case when tasks.status in ('open','in_progress') then 0 else 1 end")->orderByRaw('tasks.due_at is null')->orderBy('tasks.due_at')->orderByDesc('tasks.id')
            ->paginate(self::PER_PAGE);

        return view('workspace.tasks.list', $data + ['tasks' => $paged, 'about' => fn (Task $t) => $tasks->about($t, $user)]);
    }

    /**
     * What this person may see, narrowed by the chosen filters.
     *
     * @return Builder<Task>
     */
    private function base(User $user): Builder
    {
        $me = $user->getKey();

        return app(TaskAccess::class)->restrict(Task::query(), $user)
            ->when($this->show === 'mine', fn (Builder $q) => $q->where('tasks.assigned_to', $me))
            ->when($this->show === 'given', fn (Builder $q) => $q->where('tasks.created_by', $me)->where('tasks.assigned_to', '<>', $me))
            ->when($this->show === 'team', fn (Builder $q) => $q->where('tasks.assigned_to', '<>', $me))
            ->when(ctype_digit($this->person), fn (Builder $q) => $q->where('tasks.assigned_to', (int) $this->person))
            ->when(TaskPriority::tryFrom($this->priority) !== null, fn (Builder $q) => $q->where('tasks.priority', $this->priority))
            ->when(trim($this->search) !== '', fn (Builder $q) => $q->where('tasks.title', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], trim($this->search)).'%'));
    }

    private function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
