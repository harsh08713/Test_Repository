<div class="mx-auto max-w-4xl space-y-5">
    <div>
        <p class="text-sm text-slate-500"><a href="{{ route('hr.employees.index') }}" wire:navigate class="hover:text-brand-700">Employees</a></p>
        <h1 class="mt-1 text-2xl font-semibold text-slate-900">{{ $editing ? 'Change employee details' : 'Add an employee' }}</h1>
        @if ($editing)
            <p class="mt-1 text-sm text-slate-500">Department, designation, branch, manager and employment type are changed from the employee’s page with “Change job”, so the change is dated and kept in the history.</p>
        @endif
    </div>

    <form wire:submit="save" class="space-y-5" novalidate>
        <div class="card">
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Who</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-3">
                <div><label for="ef-code" class="label">Employee code</label><input id="ef-code" type="text" wire:model="f.code" class="input font-mono" maxlength="30">@error('code')<p class="error">{{ $message }}</p>@enderror</div>
                <div class="sm:col-span-2"><label for="ef-name" class="label">Full name</label><input id="ef-name" type="text" wire:model="f.name" class="input" maxlength="150">@error('name')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="ef-wmail" class="label">Work e-mail</label><input id="ef-wmail" type="email" wire:model="f.work_email" class="input" maxlength="150">@error('work_email')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="ef-mobile" class="label">Mobile</label><input id="ef-mobile" type="text" inputmode="numeric" wire:model="f.mobile" class="input" maxlength="10">@error('mobile')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="ef-pmail" class="label">Personal e-mail</label><input id="ef-pmail" type="email" wire:model="f.personal_email" class="input" maxlength="150">@error('personal_email')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="ef-dob" class="label">Date of birth</label><input id="ef-dob" type="date" wire:model="f.date_of_birth" class="input">@error('date_of_birth')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="ef-gender" class="label">Gender</label><select id="ef-gender" wire:model="f.gender" class="input"><option value="">—</option>@foreach ($genders as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select>@error('gender')<p class="error">{{ $message }}</p>@enderror</div>
                <div><label for="ef-marital" class="label">Marital status</label><input id="ef-marital" type="text" wire:model="f.marital_status" class="input" maxlength="12"></div>
                <div><label for="ef-blood" class="label">Blood group</label><input id="ef-blood" type="text" wire:model="f.blood_group" class="input" maxlength="4"></div>
                <div class="sm:col-span-3"><label for="ef-addr" class="label">Address</label><textarea id="ef-addr" rows="2" wire:model="f.address" class="input" maxlength="1000"></textarea></div>
                <div><label for="ef-city" class="label">City</label><input id="ef-city" type="text" wire:model="f.city" class="input" maxlength="100"></div>
                <div><label for="ef-state" class="label">State</label><input id="ef-state" type="text" wire:model="f.state" class="input" maxlength="100"></div>
                <div><label for="ef-pin" class="label">PIN code</label><input id="ef-pin" type="text" inputmode="numeric" wire:model="f.pin_code" class="input" maxlength="6">@error('pin_code')<p class="error">{{ $message }}</p>@enderror</div>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="text-sm font-semibold text-slate-900">Employment</h2></div>
            <div class="card-body grid gap-4 sm:grid-cols-3">
                <div><label for="ef-join" class="label">Joining date</label><input id="ef-join" type="date" wire:model="f.joining_date" class="input">@error('joining_date')<p class="error">{{ $message }}</p>@enderror</div>
                <div>
                    <label for="ef-shift" class="label">Shift</label>
                    <select id="ef-shift" wire:model="f.shift_id" class="input"><option value="">The company’s default shift</option>@foreach ($shifts as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                    @error('shift_id')<p class="error">{{ $message }}</p>@enderror
                </div>
                @unless ($editing)
                    <div>
                        <label for="ef-branch" class="label">Branch</label>
                        <select id="ef-branch" wire:model="f.location_id" class="input"><option value="">Select…</option>@foreach ($branches as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                        @error('location_id')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="ef-type" class="label">Employment type</label>
                        <select id="ef-type" wire:model.live="f.employment_type" class="input">@foreach ($types as $k => $n)<option value="{{ $k }}">{{ $n }}</option>@endforeach</select>
                        @error('employment_type')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="ef-dept" class="label">Department</label>
                        <select id="ef-dept" wire:model="f.department_id" class="input"><option value="">—</option>@foreach ($departments as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                        @error('department_id')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="ef-desig" class="label">Designation</label>
                        <select id="ef-desig" wire:model="f.designation_id" class="input"><option value="">—</option>@foreach ($designations as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                        @error('designation_id')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    <div>
                        <label for="ef-mgr" class="label">Reports to</label>
                        <select id="ef-mgr" wire:model="f.reports_to_id" class="input"><option value="">— nobody —</option>@foreach ($managers as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select>
                        @error('reports_to_id')<p class="error">{{ $message }}</p>@enderror
                    </div>
                    @if (($f['employment_type'] ?? '') === 'probation')
                        <div><label for="ef-prob" class="label">Probation ends on</label><input id="ef-prob" type="date" wire:model="f.probation_end_date" class="input">@error('probation_end_date')<p class="error">{{ $message }}</p>@enderror</div>
                    @endif
                @endunless
                <div class="sm:col-span-3"><label for="ef-rem" class="label">Remarks</label><textarea id="ef-rem" rows="2" wire:model="f.remarks" class="input" maxlength="1000"></textarea></div>
            </div>
        </div>

        @error('employee')<div class="rounded-lg border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700" role="alert">{{ $message }}</div>@enderror

        <div class="flex justify-end gap-3">
            <a href="{{ $editing ? route('hr.employees.show', $employeeId) : route('hr.employees.index') }}" wire:navigate class="btn-secondary">Cancel</a>
            <button type="submit" class="btn-primary" wire:loading.attr="disabled">Save</button>
        </div>
    </form>
</div>
