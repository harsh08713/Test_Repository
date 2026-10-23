<?php

namespace App\Modules\HR\Http\Livewire;

use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Services\EmployeeAccess;
use App\Modules\Masters\Models\Department;
use App\Modules\Shared\Models\Location;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Employees')]
class EmployeeList extends Component
{
    use WithPagination;

    public const PER_PAGE = 25;

    #[Url(as: 'q')]
    public string $search = '';

    #[Url(as: 'dept')]
    public string $department = '';

    #[Url(as: 'branch')]
    public string $branch = '';

    #[Url(as: 'state')]
    public string $state = 'current';

    #[Url(as: 'type')]
    public string $type = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Employee::class);
    }

    public function updating(string $property): void
    {
        if (in_array($property, ['search', 'department', 'branch', 'state', 'type'], true)) {
            $this->resetPage();
        }
    }

    public function render(EmployeeAccess $access): View
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        $term = trim($this->search);
        $like = '%'.addcslashes($term, '%_\\').'%';

        $employees = $access->restrict(Employee::query(), $user)->with(['department:id,name', 'designation:id,name', 'manager:id,name', 'location:id,name'])
            ->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('name', 'like', $like)->orWhere('code', 'like', $like)->orWhere('work_email', 'like', $like)->orWhere('mobile', 'like', $like)))
            ->when(ctype_digit($this->department), fn (Builder $q) => $q->where('department_id', (int) $this->department))
            ->when(ctype_digit($this->branch), fn (Builder $q) => $q->where('location_id', (int) $this->branch))
            ->when(array_key_exists($this->type, Employee::TYPES), fn (Builder $q) => $q->where('employment_type', $this->type))
            ->when($this->state === 'current', fn (Builder $q) => $q->whereIn('employment_status', ['active', 'notice']))
            ->when(in_array($this->state, ['active', 'notice', 'exited'], true), fn (Builder $q) => $q->where('employment_status', $this->state))
            ->orderBy('name')->orderBy('id')->paginate(self::PER_PAGE);

        return view('hr.employees', [
            'employees' => $employees, 'types' => Employee::TYPES,
            'departments' => Department::query()->orderBy('name')->pluck('name', 'id')->all(),
            'branches' => Location::query()->orderBy('name')->pluck('name', 'id')->all(),
            'canCreate' => Gate::allows('create', Employee::class),
        ]);
    }
}
