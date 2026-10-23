<?php

namespace App\Modules\HR\Http\Livewire;

use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\Shift;
use App\Modules\HR\Services\EmployeeService;
use App\Modules\Masters\Models\Department;
use App\Modules\Masters\Models\Designation;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Employee')]
class EmployeeForm extends Component
{
    #[Locked]
    public ?int $employeeId = null;

    /** @var array<string, string> */
    public array $f = [];

    public function mount(EmployeeService $service, ?int $editing = null): void
    {
        $keys = ['code', 'name', 'work_email', 'personal_email', 'mobile', 'date_of_birth', 'gender', 'marital_status', 'blood_group', 'address', 'city', 'state', 'pin_code',
            'department_id', 'designation_id', 'reports_to_id', 'shift_id', 'location_id', 'employment_type', 'joining_date', 'probation_end_date', 'remarks'];
        $this->f = array_fill_keys($keys, '');

        if ($editing !== null) {
            $employee = Employee::query()->findOrFail($editing);
            Gate::authorize('update', $employee);

            $this->employeeId = $employee->id;

            foreach ($keys as $k) {
                $v = $employee->{$k};
                $this->f[$k] = $v instanceof \DateTimeInterface ? $v->format('Y-m-d') : (string) ($v ?? '');
            }

            return;
        }

        Gate::authorize('create', Employee::class);
        $this->f['code'] = $service->suggestCode();
        $this->f['employment_type'] = 'permanent';
        $this->f['joining_date'] = now()->toDateString();
        $this->f['location_id'] = (string) (app(TenantContext::class)->activeLocationId() ?? '');
    }

    public function save(EmployeeService $service): void
    {
        $this->resetErrorBag();
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        try {
            $employee = $this->employeeId === null
                ? $service->create($this->f, $user)
                : $service->update(Employee::query()->findOrFail($this->employeeId), $this->f, $user);
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        }

        session()->flash('status', $this->employeeId === null ? 'Employee added.' : 'Employee saved.');
        $this->redirectRoute('hr.employees.show', $employee, navigate: false);
    }

    public function render(): View
    {
        $editing = $this->employeeId !== null;

        return view('hr.employee-form', [
            'editing' => $editing, 'types' => Employee::TYPES, 'genders' => Employee::GENDERS,
            'departments' => Department::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            'designations' => Designation::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            'shifts' => Shift::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all(),
            'branches' => Location::query()->orderBy('name')->pluck('name', 'id')->all(),
            'managers' => Employee::query()->whereIn('employment_status', ['active', 'notice'])->when($editing, fn ($q) => $q->whereKeyNot($this->employeeId))->orderBy('name')
                ->get(['id', 'code', 'name'])->mapWithKeys(fn (Employee $e) => [$e->id => $e->code.' · '.$e->name])->all(),
        ]);
    }
}
