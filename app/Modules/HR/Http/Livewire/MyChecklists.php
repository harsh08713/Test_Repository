<?php

namespace App\Modules\HR\Http\Livewire;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeChecklistItem;
use App\Modules\HR\Services\ChecklistService;
use App\Modules\HR\Services\EmployeeAccess;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Onboarding and exit lists: the lines the signed-in person is to do, and (for HR and managers) those of the people in their reach. HR can start a list by hand. */
#[Title('Onboarding and exit')]
class MyChecklists extends Component
{
    use ActsAsStaff;

    #[Url(as: 'show')]
    public string $show = 'open';

    public int $noting = 0;

    public string $note = '';

    public string $startFor = '';

    public string $startKind = 'onboarding';

    public function startNoting(int $id): void
    {
        $this->resetErrorBag();
        $this->noting = $this->noting === $id ? 0 : $id;
        $this->note = '';
    }

    public function done(ChecklistService $service, int $id): void
    {
        $item = $service->visibleTo($this->actor())->with('employee')->findOrFail($id);

        if ($this->attempt(fn () => $service->tick($item, $this->actor(), $this->note), 'Marked done.')) {
            $this->noting = 0;
            $this->note = '';
        }
    }

    public function startList(ChecklistService $service): void
    {
        abort_unless($this->actor()->can('checklist.edit'), 403);

        $employee = app(EmployeeAccess::class)->restrict(Employee::query(), $this->actor())->findOrFail((int) $this->startFor);
        $added = 0;

        if ($this->attempt(function () use ($service, $employee, &$added) {
            $added = $service->start($employee, $this->startKind);
        }, 'Done.')) {
            session()->flash('status', $added === 0 ? 'Nothing to add: the list was already started, or the standard list is empty.' : "Started: {$added} items added.");
        }
    }

    public function render(ChecklistService $service): View
    {
        $user = $this->actor();
        $query = $service->visibleTo($user)->with('employee:id,name,code,reports_to_id,user_id')
            ->when($this->show === 'open', fn ($q) => $q->whereNull('done_at'))->when($this->show === 'done', fn ($q) => $q->whereNotNull('done_at'))
            ->when($this->show === 'late', fn ($q) => $q->whereNull('done_at')->where('due_on', '<', now()->toDateString()))
            ->orderByRaw('done_at is not null')->orderBy('due_on')->orderBy('id');

        $rows = $query->limit(300)->get();

        return view('hr.my-checklists', [
            'rows' => $rows->groupBy('employee_id'), 'can' => fn (EmployeeChecklistItem $i) => $service->canTick($user, $i), 'canStart' => $user->can('checklist.edit'),
            'people' => $user->can('checklist.edit') ? app(EmployeeAccess::class)->restrict(Employee::query(), $user)->whereIn('employment_status', ['active', 'notice'])->orderBy('name')->get(['id', 'name', 'code']) : collect(),
        ]);
    }
}
