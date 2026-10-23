<?php

namespace App\Modules\Shared\Models;

use App\Modules\Shared\Models\Concerns\Auditable;
use App\Modules\Shared\Models\Concerns\HasAuditColumns;
use App\Modules\Shared\Models\Scopes\AccessibleCompanyScope;
use App\Modules\Shared\Policies\CompanyPolicy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * The tenant root: every other record belongs to a company.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $legal_name
 * @property string|null $gstin
 * @property string|null $pan
 * @property string|null $cin
 * @property string|null $address_line1
 * @property string|null $address_line2
 * @property string|null $city
 * @property string|null $state
 * @property string|null $state_code
 * @property string|null $pin_code
 * @property string|null $phone
 * @property string|null $email
 * @property string|null $logo_path
 * @property int $financial_year_start_month
 * @property string $base_currency
 * @property string $status
 * @property string|null $remarks
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
#[Fillable([
    'code', 'name', 'legal_name', 'gstin', 'pan', 'cin',
    'address_line1', 'address_line2', 'city', 'state', 'state_code', 'pin_code',
    'phone', 'email', 'logo_path', 'financial_year_start_month', 'base_currency',
    'status', 'remarks',
])]
#[ScopedBy([AccessibleCompanyScope::class])]
#[UsePolicy(CompanyPolicy::class)]
class Company extends Model
{
    use Auditable, HasAuditColumns, SoftDeletes;

    /** Same defaults as the table, so audit entries never show null for an untouched default. */
    protected $attributes = [
        'status' => 'active',
        'base_currency' => 'INR',
        'financial_year_start_month' => 4,
    ];

    protected function casts(): array
    {
        return [
            'financial_year_start_month' => 'integer',
        ];
    }

    /** @return HasMany<Location, $this> */
    public function locations(): HasMany
    {
        return $this->hasMany(Location::class);
    }
}
