<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Reports\Support\ReportColumn as C;
use App\Modules\Reports\Support\ReportInput;
use App\Modules\Workspace\Enums\TaskPriority;
use App\Modules\Workspace\Enums\TaskStatus;
use App\Modules\Workspace\Models\Task;
use App\Modules\Workspace\Services\TaskAccess;
use Illuminate\Database\Eloquent\Builder;

/** Tasks past their deadline (or all tasks, by state), with whom they are with and what they are about. */
final class OverdueTasks extends Report
{
    public function key(): string
    {
        return 'overdue-tasks';
    }

    public function title(): string
    {
        return 'Tasks and overdue tasks';
    }

    public function description(): string
    {
        return 'The tasks you may see with their deadline, who has them and what they are about. Starts on the ones that are overdue.';
    }

    public function group(): string
    {
        return 'Control';
    }

    public function permission(): string
    {
        return 'task.view';
    }

    public function filters(): array
    {
        return ['date', 'branch', 'person', 'team', 'choice'];
    }

    public function dateLabel(): string
    {
        return 'Deadline';
    }

    public function choiceLabel(): string
    {
        return 'Show';
    }

    public function choices(): array
    {
        return ['overdue' => 'Overdue (to do or in progress, past the deadline)', 'live' => 'To do or in progress', 'done' => 'Done', 'cancelled' => 'Called off'];
    }

    public function defaultChoice(): string
    {
        return 'overdue';
    }

    public function startsOnThisMonth(): bool
    {
        return false;
    }

    public function columns(): array
    {
        return [
            new C('title', 'Task'), new C('about_type', 'About'), new C('about_number', 'Record'), new C('assignee', 'With'), new C('creator', 'Given by'), new C('priority_label', 'Priority'),
            new C('state', 'State'), new C('due_at', 'Deadline', C::DATETIME), new C('days_overdue', 'Days overdue', C::NUMBER), new C('completed_at', 'Done at', C::DATETIME),
        ];
    }

    public function query(ReportInput $input, User $user): Builder
    {
        $join = fn (string $table, string $alias) => [$table, fn ($j) => $j->on("{$table}.id", '=', 'tasks.subject_id')->where('tasks.subject_type', '=', $alias)];

        $query = app(TaskAccess::class)->restrict(Task::query(), $user)
            ->leftJoin('users as who', 'who.id', '=', 'tasks.assigned_to')
            ->leftJoin('users as maker', 'maker.id', '=', 'tasks.created_by')
            ->leftJoin(...$join('leads', 'lead'))
            ->leftJoin(...$join('deals', 'deal'))
            ->leftJoin(...$join('quotations', 'quotation'))
            ->leftJoin(...$join('sales_orders', 'sales_order'))
            ->leftJoin(...$join('ledgers', 'ledger'))
            ->select(['tasks.id', 'tasks.title', 'tasks.status', 'tasks.priority', 'tasks.due_at', 'tasks.completed_at', 'tasks.subject_type', 'who.name as assignee', 'maker.name as creator'])
            ->selectRaw('coalesce(leads.lead_number, deals.deal_number, quotations.quotation_number, sales_orders.so_number, ledgers.code) as about_number')
            ->selectRaw("case when tasks.status in ('open','in_progress') and tasks.due_at < now() then datediff(curdate(), date(tasks.due_at)) end as days_overdue");

        $this->between($query, 'tasks.due_at', $input, true);
        $input->branch === null || $query->where('tasks.location_id', $input->branch);
        $input->peopleIds === null || $query->whereIn('tasks.assigned_to', $input->peopleIds);

        match ($input->choice) {
            'overdue' => $query->whereIn('tasks.status', TaskStatus::liveValues())->where('tasks.due_at', '<', now()),
            'live' => $query->whereIn('tasks.status', TaskStatus::liveValues()),
            'done' => $query->where('tasks.status', 'done'),
            'cancelled' => $query->where('tasks.status', 'cancelled'),
            default => null,
        };

        return $query->orderBy('tasks.due_at')->orderBy('tasks.id');
    }

    public function present(object $row): array
    {
        $out = parent::present($row);
        $out['about_type'] = match ((string) $row->getRawOriginal('subject_type')) {
            'lead' => 'Lead', 'deal' => 'Deal', 'quotation' => 'Quotation', 'sales_order' => 'Sales order', 'ledger' => 'Customer', default => null,
        };
        $out['priority_label'] = TaskPriority::tryFrom((string) $row->getRawOriginal('priority'))?->label();
        $out['state'] = TaskStatus::tryFrom((string) $row->getRawOriginal('status'))?->label();

        return $out;
    }

    public function summary(ReportInput $input, User $user): array
    {
        $t = $this->totals($this->query($input, $user), "count(*) as total, coalesce(sum(tasks.status in ('open','in_progress') and tasks.due_at < now()), 0) as overdue, coalesce(sum(tasks.status = 'done'), 0) as done");

        return ['Tasks' => number_format((int) ($t->total ?? 0)), 'Overdue' => number_format((int) ($t->overdue ?? 0)), 'Done' => number_format((int) ($t->done ?? 0))];
    }
}
