<?php

namespace App\Modules\HR\Http\Livewire;

use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\LeaveRequest;
use App\Modules\HR\Services\LeaveService;
use App\Modules\HR\Services\StaffReach;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/** Leave requests of the people the viewer manages (or everyone, for HR): what is waiting for them, and the history. Decisions go through LeaveService. */
#[Title('Leave requests')]
class LeaveRequestList extends Component
{
    use ActsAsStaff, WithPagination;

    public const PER_PAGE = 25;

    #[Url(as: 'state')]
    public string $state = 'pending';

    #[Url(as: 'q')]
    public string $search = '';

    /** the request a note is being typed for, and what kind of decision it is: reject | cancel */
    public int $noting = 0;

    public string $noteKind = '';

    public string $note = '';

    public function mount(): void
    {
        $user = $this->actor();
        abort_unless($user->can('leave.view') || $user->can('leave.approve_all'), 403);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['state', 'search'], true)) {
            $this->resetPage();
        }
    }

    public function approveRequest(LeaveService $leave, int $id): void
    {
        $this->attempt(fn () => $leave->approve($this->find($id), $this->actor()), 'Leave approved.');
    }

    public function startNote(int $id, string $kind): void
    {
        $this->resetErrorBag();
        $this->noting = $this->noting === $id && $this->noteKind === $kind ? 0 : $id;
        $this->noteKind = $kind;
        $this->note = '';
    }

    public function confirmNote(LeaveService $leave): void
    {
        $request = $this->find($this->noting);
        $kind = $this->noteKind;

        $ok = $this->attempt(fn () => $kind === 'reject' ? $leave->reject($request, $this->actor(), $this->note) : $leave->cancel($request, $this->actor(), $this->note), $kind === 'reject' ? 'Leave turned down.' : 'Leave cancelled and the days given back.');

        if ($ok) {
            $this->noting = 0;
        }
    }

    private function find(int $id): LeaveRequest
    {
        return $this->scope(LeaveRequest::query())->findOrFail($id);
    }

    /**
     * @param  Builder<LeaveRequest>  $query
     * @return Builder<LeaveRequest>
     */
    private function scope(Builder $query): Builder
    {
        return app(StaffReach::class)->restrict($query, $this->actor(), 'leave.approve_all', 'leave.view');
    }

    public function render(StaffReach $reach): View
    {
        $user = $this->actor();
        $term = trim($this->search);
        $own = $this->ownEmployee();

        $requests = $this->scope(LeaveRequest::query())->with(['employee:id,name,code,user_id', 'type:id,name', 'decider:id,name'])
            ->when(array_key_exists($this->state, LeaveRequest::STATES), fn (Builder $q) => $q->where('state', $this->state))
            ->when($term !== '', fn (Builder $q) => $q->whereIn('employee_id', Employee::query()->where('name', 'like', '%'.addcslashes($term, '%_\\').'%')->select('id')))
            ->when($own !== null && ! $user->can('leave.approve_all'), fn (Builder $q) => $q->where('employee_id', '!=', $own->id))
            ->orderByRaw("state = 'pending' desc")->orderBy('from_date')->orderBy('id')->paginate(self::PER_PAGE);

        $can = $requests->getCollection()->mapWithKeys(fn (LeaveRequest $r) => [$r->id => $r->employee !== null && $reach->decides($user, $r->employee, 'leave.approve_all', 'leave.approve')]);

        return view('hr.leave-requests', ['requests' => $requests, 'can' => $can, 'states' => LeaveRequest::STATES]);
    }
}
