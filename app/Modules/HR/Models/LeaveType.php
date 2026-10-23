<?php

namespace App\Modules\HR\Models;

use App\Modules\HR\Policies\LeaveTypePolicy;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;

/**
 * A kind of leave and its rules. Days are in halves. `accrual`: yearly (the whole quota at the start of the leave year, in proportion for someone who joins during
 * it), monthly (a twelfth a month, in halves) or none (HR grants it). What is unused at the year end carries forward up to `carry_forward_max`; the rest lapses.
 *
 * @property string $name
 * @property bool $is_paid
 * @property numeric-string $annual_quota
 * @property string $accrual
 * @property numeric-string $carry_forward_max
 * @property bool $allow_half_day
 * @property bool $allow_negative
 * @property int|null $max_days_per_request
 */
#[Fillable(['code', 'name', 'is_paid', 'annual_quota', 'accrual', 'carry_forward_max', 'allow_half_day', 'allow_negative', 'max_days_per_request', 'status', 'remarks'])]
#[UsePolicy(LeaveTypePolicy::class)]
class LeaveType extends TenantModel
{
    public const ACCRUALS = ['yearly' => 'All at the start of the year', 'monthly' => 'A twelfth every month', 'none' => 'None: HR grants it'];

    protected function casts(): array
    {
        return ['is_paid' => 'boolean', 'allow_half_day' => 'boolean', 'allow_negative' => 'boolean', 'annual_quota' => 'decimal:1', 'carry_forward_max' => 'decimal:1'];
    }
}
