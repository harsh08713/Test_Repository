<?php

namespace App\Modules\HR\Http\Livewire;

use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeContact;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Services\EmployeeAccess;
use App\Modules\HR\Services\EmployeeService;
use App\Modules\Masters\Models\Department;
use App\Modules\Masters\Models\Designation;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Models\Scopes\Tenancy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * One employee file: profile, contacts, papers and history, and the actions on it (change of job, confirmation, linking a login, notice, exit, re-joining).
 * Every action goes through EmployeeService; this component only collects what was typed and shows what came back.
 */
#[Title('Employee')]
class EmployeeShow extends Component
{
    use WithFileUploads;

    #[Locked]
    public int $employeeId = 0;

    #[Url(as: 'tab')]
    public string $tab = 'overview';

    /** which action panel is open: job | confirm | link | notice | rejoin | contact | paper | '' */
    public string $panel = '';

    /** @var array<string, string> */
    public array $job = [];

    /** @var array<string, string> */
    public array $notice = ['exit_date' => '', 'exit_reason' => ''];

    /** @var array<string, string> */
    public array $contact = ['name' => '', 'relationship' => '', 'mobile' => '', 'is_primary' => ''];

    /** @var array<string, string> */
    public array $paper = ['doc_type' => 'id_proof', 'title' => '', 'expires_on' => ''];

    public ?TemporaryUploadedFile $file = null;

    public string $confirmOn = '';

    public string $rejoinOn = '';

    public string $linkUserId = '';

    public function mount(Employee $employee): void
    {
        Gate::authorize('view', $employee);
        $this->employeeId = $employee->id;
        $this->confirmOn = $this->rejoinOn = now()->toDateString();
    }

    private function employee(): Employee
    {
        $employee = Employee::query()->findOrFail($this->employeeId);
        Gate::authorize('view', $employee);

        return $employee;
    }

    private function actor(): User
    {
        $user = Auth::user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    public function openPanel(string $panel): void
    {
        $this->resetErrorBag();
        $this->panel = $this->panel === $panel ? '' : $panel;

        if ($panel === 'job' && $this->panel === 'job') {
            $e = $this->employee();
            $this->job = [
                'department_id' => (string) $e->department_id, 'designation_id' => (string) $e->designation_id, 'location_id' => (string) $e->location_id,
                'reports_to_id' => (string) $e->reports_to_id, 'employment_type' => $e->employment_type, 'effective_on' => now()->toDateString(), 'remarks' => '',
            ];
        }
    }

    /** Run an action, show what the service refused in the form, or say it was done. */
    private function run(\Closure $action, string $done): void
    {
        $this->resetErrorBag();

        try {
            $action();
        } catch (ValidationException $e) {
            $this->setErrorBag($e->validator->errors());

            return;
        } catch (AuthorizationException $e) {
            $this->addError('action', $e->getMessage());

            return;
        }

        session()->flash('status', $done);
        $this->panel = '';
    }

    public function saveJob(EmployeeService $service): void
    {
        $this->run(fn () => $service->changeJob($this->employee(), $this->job, (string) ($this->job['effective_on'] ?? ''), $this->job['remarks'] ?? null, $this->actor()), 'Job details changed and written to the history.');
    }

    public function confirmEmployee(EmployeeService $service): void
    {
        $this->run(fn () => $service->confirm($this->employee(), $this->confirmOn, $this->actor()), 'Confirmed.');
    }

    public function linkLogin(EmployeeService $service): void
    {
        $user = ctype_digit($this->linkUserId) ? User::query()->find((int) $this->linkUserId) : null;
        $this->run(fn () => $service->linkUser($this->employee(), $user, $this->actor()), $user === null ? 'Login unlinked.' : 'Login linked.');
        $this->linkUserId = '';
    }

    public function giveNotice(EmployeeService $service): void
    {
        $this->run(fn () => $service->recordNotice($this->employee(), $this->notice['exit_date'], $this->notice['exit_reason'], $this->actor()), 'Notice recorded.');
    }

    public function withdrawNotice(EmployeeService $service): void
    {
        $this->run(fn () => $service->withdrawNotice($this->employee(), $this->actor()), 'Notice withdrawn.');
    }

    public function rejoinEmployee(EmployeeService $service): void
    {
        $this->run(fn () => $service->rejoin($this->employee(), $this->rejoinOn, $this->actor()), 'Welcome back: the person has re-joined. Their login stays off until a user administrator switches it on.');
    }

    public function addContact(EmployeeService $service): void
    {
        $this->run(function () use ($service) {
            $service->addContact($this->employee(), $this->contact, $this->actor());
            $this->contact = ['name' => '', 'relationship' => '', 'mobile' => '', 'is_primary' => ''];
        }, 'Contact added.');
        $this->tab = 'contacts';
    }

    public function removeContact(EmployeeService $service, int $contactId): void
    {
        $contact = EmployeeContact::query()->where('employee_id', $this->employeeId)->findOrFail($contactId);
        $this->run(fn () => $service->removeContact($contact, $this->actor()), 'Contact removed.');
    }

    public function savePaper(EmployeeService $service): void
    {
        $this->run(function () use ($service) {
            if ($this->file === null) {
                throw ValidationException::withMessages(['file' => 'Choose the file to upload.']);
            }

            $service->addDocument($this->employee(), $this->file, $this->paper, $this->actor());
            $this->file = null;
            $this->paper = ['doc_type' => 'id_proof', 'title' => '', 'expires_on' => ''];
        }, 'Paper added to the file.');
        $this->tab = 'papers';
    }

    public function removePaper(EmployeeService $service, int $documentId): void
    {
        $document = EmployeeDocument::query()->where('employee_id', $this->employeeId)->findOrFail($documentId);
        $this->run(fn () => $service->removeDocument($document, $this->actor()), 'Paper removed.');
    }

    public function render(EmployeeAccess $access): View
    {
        $employee = Employee::query()->with(['department', 'designation', 'manager:id,name,code', 'location:id,name', 'user:id,name,email'])->findOrFail($this->employeeId);
        Gate::authorize('view', $employee);
        $user = $this->actor();
        $sensitive = $access->seesSensitive($user, $employee);
        $canEdit = Gate::allows('update', $employee) && ! $employee->isExited();
        $canExit = $user->can('employee.record_exit');

        return view('hr.employee-show', [
            'e' => $employee, 'sensitive' => $sensitive, 'canEdit' => $canEdit, 'canExit' => $canExit, 'canPapers' => $user->can('employee.manage_documents'), 'own' => $access->isOwn($user, $employee),
            'reports' => $employee->reports()->whereIn('employment_status', ['active', 'notice'])->orderBy('name')->get(['id', 'name', 'code']),
            'events' => $employee->events()->with('recorder:id,name')->orderByDesc('effective_on')->orderByDesc('id')->get(),
            'contacts' => $sensitive ? $employee->contacts()->orderByDesc('is_primary')->orderBy('id')->get() : collect(),
            'papers' => $sensitive ? $employee->documents()->orderBy('expires_on')->orderBy('id')->get() : collect(),
            'types' => Employee::TYPES, 'docTypes' => EmployeeDocument::TYPES,
            'departments' => $canEdit ? Department::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all() : [],
            'designations' => $canEdit ? Designation::query()->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all() : [],
            'branches' => $canEdit ? Location::query()->orderBy('name')->pluck('name', 'id')->all() : [],
            'managers' => $canEdit ? Employee::query()->whereIn('employment_status', ['active', 'notice'])->whereKeyNot($employee->id)->orderBy('name')->get(['id', 'code', 'name'])
                ->mapWithKeys(fn (Employee $m) => [$m->id => $m->code.' · '.$m->name])->all() : [],
            'logins' => $canEdit && $employee->user_id === null ? $this->freeLogins($employee) : [],
        ]);
    }

    /** @return array<int, string> logins of this company that are not linked to an employee file yet */
    private function freeLogins(Employee $employee): array
    {
        $members = DB::table('company_user')->where('company_id', $employee->company_id)->pluck('user_id');
        $taken = Employee::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $employee->company_id)->whereNotNull('user_id')->pluck('user_id');

        return User::query()->whereIn('id', $members)->whereNotIn('id', $taken)->where('is_active', true)->orderBy('name')->get(['id', 'name', 'email'])
            ->mapWithKeys(fn (User $u) => [$u->id => $u->name.' ('.$u->email.')'])->all();
    }
}
