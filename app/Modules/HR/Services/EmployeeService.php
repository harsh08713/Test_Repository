<?php

namespace App\Modules\HR\Services;

use App\Models\User;
use App\Modules\HR\Models\Employee;
use App\Modules\HR\Models\EmployeeContact;
use App\Modules\HR\Models\EmployeeDocument;
use App\Modules\HR\Models\EmploymentEvent;
use App\Modules\HR\Models\Shift;
use App\Modules\Masters\Models\Department;
use App\Modules\Masters\Models\Designation;
use App\Modules\Shared\Models\Location;
use App\Modules\Shared\Models\Scopes\Tenancy;
use App\Modules\Shared\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * The one door for employee data: the file itself, changes of job (each written to the append-only history), notice and exit, contacts and papers.
 *
 * Rules kept here, not in the screens:
 *   - an employee code is unique in the company; a person is at least 14 and joins no more than 90 days ahead;
 *   - a manager is an active employee of the same company, never the person themself and never someone who (directly or further up) reports to them — no cycles;
 *   - a person's login is linked to one employee file only, and only if the login belongs to the company;
 *   - notice, exit and re-joining need `employee.record_exit`; the last working day switches the person's login off (`finaliseExit`, also run by `hr:sync-exits`);
 *   - a person who has left cannot be changed, only re-joined; the history is never edited.
 */
final class EmployeeService
{
    public const MAX_CONTACTS = 5;

    public const MAX_DOCUMENT_KB = 5120;

    public function __construct(private readonly TenantContext $tenant) {}

    /** The next free code in the form EMP-00001 (a suggestion; the unique key is what decides). */
    public function suggestCode(): string
    {
        $max = 0;

        foreach (Employee::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->company())->where('code', 'like', 'EMP-%')->pluck('code') as $code) {
            if (preg_match('/^EMP-(\d+)$/', (string) $code, $m) === 1) {
                $max = max($max, (int) $m[1]);
            }
        }

        return 'EMP-'.str_pad((string) ($max + 1), 5, '0', STR_PAD_LEFT);
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function create(array $input, User $actor): Employee
    {
        Gate::forUser($actor)->authorize('create', Employee::class);
        $data = $this->validated($input, null);

        return DB::transaction(function () use ($data, $actor) {
            $employee = new Employee;
            $employee->fill($data['fill']);
            $employee->forceFill(['location_id' => $data['location_id'], 'employment_status' => 'active']);
            $employee->save();

            $this->event($employee, 'joined', $employee->joining_date->toDateString(), null, $this->summary($employee), null, $actor);
            app(ChecklistService::class)->start($employee, 'onboarding');

            return $employee;
        });
    }

    /**
     * The personal and contact details of the file. A change of department, designation, branch, manager or type is a change of **job** (changeJob),
     * because it has a date and goes into the history.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(Employee $employee, array $input, User $actor): Employee
    {
        Gate::forUser($actor)->authorize('update', $employee);
        $this->refuseIfExited($employee);

        $data = $this->validated($input + $this->jobNow($employee), $employee);

        return DB::transaction(function () use ($employee, $data) {
            $employee->fill(array_diff_key($data['fill'], array_flip(['department_id', 'designation_id', 'reports_to_id', 'employment_type'])));
            $employee->save();

            return $employee;
        });
    }

    /**
     * Move, promote, re-assign or change the type of an employee, as of a date. One history line for each thing that actually changed.
     *
     * @param  array<string, mixed>  $input  any of department_id, designation_id, location_id, reports_to_id, employment_type
     *
     * @throws ValidationException
     */
    public function changeJob(Employee $employee, array $input, string $effectiveOn, ?string $remarks, User $actor): Employee
    {
        Gate::forUser($actor)->authorize('update', $employee);
        $this->refuseIfExited($employee);

        $errors = [];
        $date = $this->date($effectiveOn);

        if ($date === null || $date->isFuture() || $date->lt($employee->joining_date)) {
            $errors['effective_on'] = 'Give the day it took effect: not before the person joined, and not in the future.';
        }

        $data = $this->validated($input + $this->jobNow($employee), $employee, $errors);
        $remarks = $remarks === null || trim($remarks) === '' ? null : mb_substr(trim($remarks), 0, 500);

        return DB::transaction(function () use ($employee, $data, $date, $remarks, $actor) {
            $before = $this->jobNow($employee);
            $changes = [];

            foreach (['department_id' => 'department', 'designation_id' => 'designation', 'location_id' => 'branch', 'reports_to_id' => 'manager', 'employment_type' => 'type'] as $field => $event) {
                $new = $field === 'location_id' ? $data['location_id'] : ($data['fill'][$field] ?? null);

                if ((string) ($before[$field] ?? '') !== (string) ($new ?? '')) {
                    $changes[$event] = [$this->label($field, $before[$field] ?? null), $this->label($field, $new), $field, $new];
                }
            }

            foreach ($changes as $event => [$from, $to, $field, $new]) {
                $employee->forceFill([$field => $new]);
                $this->event($employee, $event, $date->toDateString(), $from, $to, $remarks, $actor);
            }

            $employee->forceFill(['shift_id' => $data['fill']['shift_id'] ?? null]);

            if ($changes !== [] || $employee->isDirty('shift_id')) {
                $employee->save();
            }

            return $employee;
        });
    }

    /** Confirm an employee after probation: the type becomes permanent and the day is kept. */
    public function confirm(Employee $employee, string $on, User $actor): Employee
    {
        Gate::forUser($actor)->authorize('update', $employee);
        $this->refuseIfExited($employee);
        $date = $this->date($on);

        if ($employee->confirmation_date !== null) {
            throw ValidationException::withMessages(['confirmed_on' => 'This person has already been confirmed.']);
        }

        if ($date === null || $date->isFuture() || $date->lt($employee->joining_date)) {
            throw ValidationException::withMessages(['confirmed_on' => 'Give the day of confirmation: not before the person joined, and not in the future.']);
        }

        return DB::transaction(function () use ($employee, $date, $actor) {
            $from = $employee->employment_type;
            $employee->forceFill(['confirmation_date' => $date->toDateString(), 'employment_type' => 'permanent'])->save();
            $this->event($employee, 'confirmed', $date->toDateString(), Employee::TYPES[$from] ?? $from, Employee::TYPES['permanent'], null, $actor);

            return $employee;
        });
    }

    /**
     * Link (or unlink, with null) the login of an employee. The login must belong to the company and not be linked to another file.
     *
     * @throws ValidationException
     */
    public function linkUser(Employee $employee, ?User $user, User $actor): Employee
    {
        Gate::forUser($actor)->authorize('update', $employee);
        $this->refuseIfExited($employee);

        if ($user !== null) {
            if (! $this->tenant->userCanAccessCompany($user, (int) $employee->company_id)) {
                throw ValidationException::withMessages(['user_id' => 'That login does not belong to this company.']);
            }

            if (Employee::withoutGlobalScopes(Tenancy::SCOPES)->where('user_id', $user->id)->whereKeyNot($employee->id)->exists()) {
                throw ValidationException::withMessages(['user_id' => 'That login is already linked to another employee.']);
            }
        }

        $employee->forceFill(['user_id' => $user?->id])->save();

        return $employee;
    }

    /**
     * Record that an employee has given notice (or been told to leave), with the last working day. A last day of today (or earlier is refused) leaves at once.
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function recordNotice(Employee $employee, string $lastDay, string $reason, User $actor): Employee
    {
        $this->authorizeExit($actor, $employee);

        if ($employee->employment_status !== 'active') {
            throw ValidationException::withMessages(['exit_date' => $employee->isExited() ? 'This person has already left.' : 'Notice has already been recorded.']);
        }

        $errors = [];
        $date = $this->date($lastDay);
        $reason = trim($reason);

        if ($date === null || $date->isPast() && ! $date->isToday() || $date->lt($employee->joining_date)) {
            $errors['exit_date'] = 'Give the last working day: today or later, and not before the person joined.';
        }

        if ($reason === '' || mb_strlen($reason) > 500) {
            $errors['exit_reason'] = 'Say why the person is leaving (up to 500 characters).';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        DB::transaction(function () use ($employee, $date, $reason, $actor) {
            $employee->forceFill(['employment_status' => 'notice', 'notice_date' => now()->toDateString(), 'exit_date' => $date->toDateString(), 'exit_reason' => $reason])->save();
            $this->event($employee, 'notice', now()->toDateString(), null, 'last day '.$date->format('d M Y'), $reason, $actor);
            app(ChecklistService::class)->start($employee, 'exit');
        });

        return $date->isToday() ? $this->finaliseExit($employee, $actor) : $employee;
    }

    /** The person is staying after all: back to active, the notice kept in the history. */
    public function withdrawNotice(Employee $employee, User $actor): Employee
    {
        $this->authorizeExit($actor, $employee);

        if ($employee->employment_status !== 'notice') {
            throw ValidationException::withMessages(['exit_date' => 'There is no notice to withdraw.']);
        }

        return DB::transaction(function () use ($employee, $actor) {
            $employee->forceFill(['employment_status' => 'active', 'notice_date' => null, 'exit_date' => null, 'exit_reason' => null])->save();
            $this->event($employee, 'notice', now()->toDateString(), 'on notice', 'withdrawn', null, $actor);

            return $employee;
        });
    }

    /**
     * The last working day has come: the person has left and their login is switched off (and its API tokens revoked). Idempotent — a person who has
     * already left is left alone — so the scheduler, a retry and a person pressing the button cannot do it twice. `$actor` is null from the scheduler.
     */
    public function finaliseExit(Employee $employee, ?User $actor = null): Employee
    {
        return DB::transaction(function () use ($employee, $actor) {
            $fresh = Employee::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($employee->id)->lockForUpdate()->firstOrFail();

            if ($fresh->employment_status === 'exited') {
                return $fresh;
            }

            $fresh->forceFill(['employment_status' => 'exited'])->save();
            $this->event($fresh, 'exited', ($fresh->exit_date ?? now())->toDateString(), null, null, $fresh->exit_reason, $actor);

            if ($fresh->user_id !== null) {
                $user = User::query()->find($fresh->user_id);

                if ($user !== null) {
                    $user->forceFill(['is_active' => false])->save();
                    $user->tokens()->delete();
                }
            }

            return $fresh;
        });
    }

    /** A person who left comes back. Their login stays off: someone with the right to manage users decides about that. */
    public function rejoin(Employee $employee, string $on, User $actor): Employee
    {
        $this->authorizeExit($actor, $employee);

        if (! $employee->isExited()) {
            throw ValidationException::withMessages(['rejoin_on' => 'Only someone who has left can re-join.']);
        }

        $date = $this->date($on);

        if ($date === null || $date->isFuture() || ($employee->exit_date !== null && $date->lt($employee->exit_date))) {
            throw ValidationException::withMessages(['rejoin_on' => 'Give the day the person came back: after the last working day, and not in the future.']);
        }

        return DB::transaction(function () use ($employee, $date, $actor) {
            $employee->forceFill(['employment_status' => 'active', 'notice_date' => null, 'exit_date' => null, 'exit_reason' => null, 'joining_date' => $date->toDateString()])->save();
            $this->event($employee, 'rejoined', $date->toDateString(), null, null, null, $actor);

            return $employee;
        });
    }

    // ---- contacts and papers ---------------------------------------------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $input  name, relationship, mobile, is_primary
     *
     * @throws ValidationException
     */
    public function addContact(Employee $employee, array $input, User $actor): EmployeeContact
    {
        Gate::forUser($actor)->authorize('update', $employee);

        $errors = [];
        $name = trim((string) ($input['name'] ?? ''));
        $relationship = trim((string) ($input['relationship'] ?? ''));
        $mobile = trim((string) ($input['mobile'] ?? ''));

        if ($name === '' || mb_strlen($name) > 150) {
            $errors['name'] = 'Enter the name (up to 150 characters).';
        }

        if ($relationship === '' || mb_strlen($relationship) > 50) {
            $errors['relationship'] = 'Enter how this person is related (up to 50 characters).';
        }

        if (preg_match('/^[6-9]\d{9}$/', $mobile) !== 1) {
            $errors['mobile'] = 'Enter a 10-digit mobile number.';
        }

        if ($employee->contacts()->count() >= self::MAX_CONTACTS) {
            $errors['name'] = 'There are already '.self::MAX_CONTACTS.' contacts; remove one first.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return DB::transaction(function () use ($employee, $name, $relationship, $mobile, $input) {
            $first = ! $employee->contacts()->exists();
            $primary = $first || (bool) ($input['is_primary'] ?? false);

            if ($primary) {
                $employee->contacts()->update(['is_primary' => false]);
            }

            $contact = new EmployeeContact;
            $contact->fill(['name' => $name, 'relationship' => $relationship, 'mobile' => $mobile, 'is_primary' => $primary]);
            $contact->forceFill(['employee_id' => $employee->id, 'company_id' => $employee->company_id])->save();

            return $contact;
        });
    }

    public function removeContact(EmployeeContact $contact, User $actor): void
    {
        $employee = Employee::query()->findOrFail($contact->employee_id);
        Gate::forUser($actor)->authorize('update', $employee);

        DB::transaction(function () use ($contact, $employee) {
            $wasPrimary = $contact->is_primary;
            $contact->delete();

            if ($wasPrimary) {
                $employee->contacts()->orderBy('id')->first()?->forceFill(['is_primary' => true])->save();
            }
        });
    }

    /**
     * Put a paper on the file. It is stored on the private disk under a random name and handed back only through the download controller.
     *
     * @param  array<string, mixed>  $meta  doc_type, title, expires_on
     *
     * @throws ValidationException
     */
    public function addDocument(Employee $employee, UploadedFile $file, array $meta, User $actor): EmployeeDocument
    {
        $this->authorizeDocuments($actor, $employee);

        $errors = [];
        $type = (string) ($meta['doc_type'] ?? '');
        $title = trim((string) ($meta['title'] ?? ''));
        $expires = ($meta['expires_on'] ?? '') === '' ? null : $this->date((string) $meta['expires_on']);

        if (! array_key_exists($type, EmployeeDocument::TYPES)) {
            $errors['doc_type'] = 'Choose what kind of paper it is.';
        }

        if ($title === '' || mb_strlen($title) > 150) {
            $errors['title'] = 'Give it a title (up to 150 characters).';
        }

        if (($meta['expires_on'] ?? '') !== '' && $expires === null) {
            $errors['expires_on'] = 'Enter the expiry date, or leave it empty.';
        }

        if (! in_array(strtolower($file->getClientOriginalExtension()), ['pdf', 'jpg', 'jpeg', 'png', 'doc', 'docx'], true) || $file->getSize() > self::MAX_DOCUMENT_KB * 1024) {
            $errors['file'] = 'Upload a PDF, image or Word file of at most '.(self::MAX_DOCUMENT_KB / 1024).' MB.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $path = $file->store('hr/'.$employee->company_id.'/'.$employee->id, 'local');

        $document = new EmployeeDocument;
        $document->fill(['doc_type' => $type, 'title' => $title, 'expires_on' => $expires?->toDateString()]);
        $document->forceFill([
            'employee_id' => $employee->id, 'company_id' => $employee->company_id, 'path' => $path,
            'original_name' => mb_substr(preg_replace('/[\x00-\x1F\x7F\/\\\\]/', '', $file->getClientOriginalName()) ?? 'file', 0, 200),
            'mime' => (string) $file->getMimeType(), 'size' => (int) $file->getSize(),
        ])->save();

        return $document;
    }

    public function removeDocument(EmployeeDocument $document, User $actor): void
    {
        $employee = Employee::query()->findOrFail($document->employee_id);
        $this->authorizeDocuments($actor, $employee);

        Storage::disk('local')->delete($document->path);
        $document->delete();
    }

    // ---- internals -------------------------------------------------------------------------------------------------------------------

    private function company(): int
    {
        return (int) $this->tenant->activeCompanyId();
    }

    private function refuseIfExited(Employee $employee): void
    {
        if ($employee->isExited()) {
            throw ValidationException::withMessages(['employee' => 'This person has left the company. Re-join them first to change the file.']);
        }
    }

    private function authorizeExit(User $actor, Employee $employee): void
    {
        if (! $actor->can('employee.record_exit') || ! Gate::forUser($actor)->allows('view', $employee)) {
            throw new AuthorizationException('You may not record notice, exit or re-joining.');
        }
    }

    private function authorizeDocuments(User $actor, Employee $employee): void
    {
        if (! $actor->can('employee.manage_documents') || ! Gate::forUser($actor)->allows('view', $employee)) {
            throw new AuthorizationException('You may not change the papers on an employee file.');
        }
    }

    /** @return array<string, mixed> */
    private function jobNow(Employee $e): array
    {
        return [
            'department_id' => $e->department_id, 'designation_id' => $e->designation_id, 'reports_to_id' => $e->reports_to_id, 'employment_type' => $e->employment_type,
            'location_id' => $e->location_id, 'shift_id' => $e->shift_id,
            'code' => $e->code, 'name' => $e->name, 'work_email' => $e->work_email, 'personal_email' => $e->personal_email, 'mobile' => $e->mobile,
            'date_of_birth' => $e->date_of_birth?->toDateString(), 'gender' => $e->gender, 'marital_status' => $e->marital_status, 'blood_group' => $e->blood_group,
            'address' => $e->address, 'city' => $e->city, 'state' => $e->state, 'pin_code' => $e->pin_code,
            'joining_date' => $e->joining_date->toDateString(), 'probation_end_date' => $e->probation_end_date?->toDateString(), 'remarks' => $e->remarks,
        ];
    }

    /**
     * Check and clean the input of a file. Returns the values to fill and the branch.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, string>  $errors  errors found by the caller already
     * @return array{fill: array<string, mixed>, location_id: int}
     *
     * @throws ValidationException
     */
    private function validated(array $input, ?Employee $editing, array $errors = []): array
    {
        $company = $this->company();
        $text = fn (string $k, int $max) => ($v = trim((string) ($input[$k] ?? ''))) === '' ? null : mb_substr($v, 0, $max);
        $id = fn (string $k) => is_numeric($input[$k] ?? null) && (int) $input[$k] > 0 ? (int) $input[$k] : null;

        $code = strtoupper(trim((string) ($input['code'] ?? '')));

        if (preg_match('/^[A-Z0-9._\/-]{1,30}$/', $code) !== 1) {
            $errors['code'] = 'Enter an employee code: letters, digits and . _ - / only (up to 30).';
        } elseif (Employee::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $company)->where('code', $code)->when($editing !== null, fn ($q) => $q->whereKeyNot($editing->id))->exists()) {
            $errors['code'] = 'That employee code is already used.';
        }

        $name = $text('name', 150);

        if ($name === null) {
            $errors['name'] = 'Enter the name.';
        }

        foreach (['work_email', 'personal_email'] as $k) {
            if ($text($k, 150) !== null && filter_var($text($k, 150), FILTER_VALIDATE_EMAIL) === false) {
                $errors[$k] = 'Enter a valid e-mail address.';
            }
        }

        if ($text('mobile', 10) !== null && preg_match('/^[6-9]\d{9}$/', (string) $text('mobile', 10)) !== 1) {
            $errors['mobile'] = 'Enter a 10-digit mobile number.';
        }

        $dob = $text('date_of_birth', 10) === null ? null : $this->date((string) $text('date_of_birth', 10));

        if ($text('date_of_birth', 10) !== null && ($dob === null || $dob->gt(CarbonImmutable::today()->subYears(14)) || $dob->lt(CarbonImmutable::today()->subYears(100)))) {
            $errors['date_of_birth'] = 'Enter a date of birth that makes the person at least 14 years old.';
        }

        $joining = $this->date((string) ($input['joining_date'] ?? ''));

        if ($joining === null || $joining->gt(CarbonImmutable::today()->addDays(90)) || $joining->lt(CarbonImmutable::parse('1980-01-01'))) {
            $errors['joining_date'] = 'Enter the joining date (not more than 90 days ahead).';
        }

        $type = (string) ($input['employment_type'] ?? 'permanent');

        if (! array_key_exists($type, Employee::TYPES)) {
            $errors['employment_type'] = 'Choose the employment type.';
        }

        $probationEnd = $text('probation_end_date', 10) === null ? null : $this->date((string) $text('probation_end_date', 10));

        if ($type === 'probation' && ($probationEnd === null || ($joining !== null && $probationEnd->lt($joining)))) {
            $errors['probation_end_date'] = 'A person on probation needs the day the probation ends, not before they join.';
        }

        $gender = $text('gender', 10);

        if ($gender !== null && ! array_key_exists($gender, Employee::GENDERS)) {
            $errors['gender'] = 'Choose male, female or other.';
        }

        $pin = $text('pin_code', 6);

        if ($pin !== null && preg_match('/^\d{6}$/', $pin) !== 1) {
            $errors['pin_code'] = 'Enter a 6-digit PIN code.';
        }

        $department = $id('department_id');
        $designation = $id('designation_id');

        if ($department !== null && ! Department::query()->where('status', 'active')->whereKey($department)->exists() && $department !== $editing?->department_id) {
            $errors['department_id'] = 'Choose an active department.';
        }

        if ($designation !== null && ! Designation::query()->where('status', 'active')->whereKey($designation)->exists() && $designation !== $editing?->designation_id) {
            $errors['designation_id'] = 'Choose an active designation.';
        }

        $shift = $id('shift_id');

        if ($shift !== null && $shift !== $editing?->shift_id && ! Shift::query()->where('status', 'active')->whereKey($shift)->exists()) {
            $errors['shift_id'] = 'Choose an active shift.';
        }

        $locationId = $id('location_id');

        if ($locationId === null || ! Location::query()->whereKey($locationId)->exists()) {
            $errors['location_id'] = 'Choose the branch the person works in.';
            $locationId = $editing === null ? 0 : $editing->location_id;
        }

        $manager = $id('reports_to_id');

        if ($manager !== null && $manager !== $editing?->reports_to_id) {
            $row = Employee::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $company)->whereKey($manager)->first();

            if ($row === null || $row->employment_status === 'exited') {
                $errors['reports_to_id'] = 'Choose an employee who is still with the company.';
            } elseif ($editing !== null && ($manager === $editing->id || $this->reportsUpTo($manager, $editing->id))) {
                $errors['reports_to_id'] = $manager === $editing->id ? 'A person cannot report to themself.' : 'That person reports to this one (directly or further up), so this would make a circle.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'location_id' => (int) $locationId,
            'fill' => [
                'code' => $code, 'name' => $name, 'work_email' => $text('work_email', 150), 'personal_email' => $text('personal_email', 150), 'mobile' => $text('mobile', 10),
                'date_of_birth' => $dob?->toDateString(), 'gender' => $gender, 'marital_status' => $text('marital_status', 12), 'blood_group' => $text('blood_group', 4),
                'address' => $text('address', 1000), 'city' => $text('city', 100), 'state' => $text('state', 100), 'pin_code' => $pin,
                'department_id' => $department, 'designation_id' => $designation, 'reports_to_id' => $manager, 'shift_id' => $shift, 'employment_type' => $type,
                'joining_date' => $joining?->toDateString(), 'probation_end_date' => $probationEnd?->toDateString(), 'remarks' => $text('remarks', 1000),
            ],
        ];
    }

    /**
     * Does $start report (directly or further up) to $target? Walks the reporting line upward without the branch scope, so a manager the actor
     * cannot see does not hide a circle; each person is visited once, so a circle that is somehow already there cannot loop it.
     */
    private function reportsUpTo(int $start, int $target): bool
    {
        $seen = [];
        $current = $start;

        while ($current !== null && ! isset($seen[$current])) {
            if ($current === $target) {
                return true;
            }

            $seen[$current] = true;
            $up = Employee::withoutGlobalScopes(Tenancy::SCOPES)->where('company_id', $this->company())->whereKey($current)->value('reports_to_id');
            $current = $up === null ? null : (int) $up;
        }

        return false;
    }

    private function label(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($field) {
            'department_id' => Department::query()->whereKey($value)->value('name'),
            'designation_id' => Designation::query()->whereKey($value)->value('name'),
            'location_id' => Location::query()->whereKey($value)->value('name'),
            'reports_to_id' => Employee::withoutGlobalScopes(Tenancy::SCOPES)->whereKey($value)->value('name'),
            'employment_type' => Employee::TYPES[$value] ?? (string) $value,
            default => (string) $value,
        };
    }

    private function summary(Employee $employee): string
    {
        return trim(($this->label('designation_id', $employee->designation_id) ?? '').' · '.($this->label('department_id', $employee->department_id) ?? ''), ' ·');
    }

    private function date(string $value): ?CarbonImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value ? CarbonImmutable::instance($parsed) : null;
    }

    private function event(Employee $employee, string $event, string $on, ?string $from, ?string $to, ?string $remarks, ?User $by): void
    {
        $row = new EmploymentEvent;
        $row->forceFill([
            'company_id' => $employee->company_id, 'employee_id' => $employee->id, 'event' => $event, 'effective_on' => $on, 'from_value' => $from === null ? null : mb_substr($from, 0, 200),
            'to_value' => $to === null ? null : mb_substr($to, 0, 200), 'remarks' => $remarks === null ? null : mb_substr($remarks, 0, 500), 'recorded_by' => $by?->id, 'created_at' => now(),
        ])->save();
    }
}
