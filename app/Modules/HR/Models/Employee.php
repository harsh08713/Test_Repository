<?php

namespace App\Modules\HR\Models;

use App\Models\User;
use App\Modules\HR\Policies\EmployeePolicy;
use App\Modules\Masters\Models\Department;
use App\Modules\Masters\Models\Designation;
use App\Modules\Shared\Models\Concerns\BelongsToLocation;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A person employed by the company. `code` is the employee code. Employment state (`employment_status`) moves only through `EmployeeService`
 * (notice, then exited; nothing sets it by hand), and every change of job is written to `employment_events`.
 *
 * @property string $name
 * @property int $location_id
 * @property int|null $shift_id
 * @property int|null $user_id
 * @property int|null $department_id
 * @property int|null $designation_id
 * @property int|null $reports_to_id
 * @property string $employment_type
 * @property Carbon $joining_date
 * @property Carbon|null $probation_end_date
 * @property Carbon|null $confirmation_date
 * @property string $employment_status
 * @property Carbon|null $notice_date
 * @property Carbon|null $exit_date
 * @property string|null $exit_reason
 * @property Carbon|null $date_of_birth
 * @property string|null $mobile
 * @property string|null $work_email
 * @property string|null $personal_email
 */
#[Fillable([
    'code', 'name', 'work_email', 'personal_email', 'mobile', 'date_of_birth', 'gender', 'marital_status', 'blood_group', 'address', 'city', 'state', 'pin_code',
    'department_id', 'designation_id', 'reports_to_id', 'shift_id', 'employment_type', 'joining_date', 'probation_end_date', 'remarks',
])]
#[UsePolicy(EmployeePolicy::class)]
class Employee extends TenantModel
{
    use BelongsToLocation, ValidatesParentCompany;

    public const TYPES = ['permanent' => 'Permanent', 'probation' => 'On probation', 'contract' => 'Contract', 'intern' => 'Intern', 'consultant' => 'Consultant'];

    public const GENDERS = ['male' => 'Male', 'female' => 'Female', 'other' => 'Other'];

    protected $attributes = ['employment_status' => 'active', 'employment_type' => 'permanent'];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date', 'joining_date' => 'date', 'probation_end_date' => 'date', 'confirmation_date' => 'date', 'notice_date' => 'date', 'exit_date' => 'date',
        ];
    }

    protected function companyScopedParents(): array
    {
        return ['department_id' => Department::class, 'designation_id' => Designation::class, 'reports_to_id' => self::class];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return BelongsTo<Designation, $this> */
    public function designation(): BelongsTo
    {
        return $this->belongsTo(Designation::class);
    }

    /** @return BelongsTo<Employee, $this> */
    public function manager(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reports_to_id');
    }

    /** @return HasMany<Employee, $this> */
    public function reports(): HasMany
    {
        return $this->hasMany(self::class, 'reports_to_id');
    }

    /** @return HasMany<EmployeeContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(EmployeeContact::class);
    }

    /** @return HasMany<EmployeeDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    /** @return HasMany<EmploymentEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(EmploymentEvent::class);
    }

    public function isExited(): bool
    {
        return $this->employment_status === 'exited';
    }
}
