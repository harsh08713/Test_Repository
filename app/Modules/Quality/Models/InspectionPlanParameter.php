<?php

namespace App\Modules\Quality\Models;

use App\Modules\Shared\Models\TenantModel;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * One thing to check on an inspection plan: a measurement between limits (either may be left open) or a pass / fail check. Lines of a plan that is waiting or approved never change.
 *
 * @property int $inspection_plan_id
 * @property int $line_no
 * @property string $name
 * @property string $kind measure|check
 * @property string|null $unit_text
 * @property string|null $min_value
 * @property string|null $max_value
 * @property bool $is_critical
 * @property string|null $method
 */
class InspectionPlanParameter extends TenantModel
{
    protected $attributes = [];

    protected static function booted(): void
    {
        $guard = function (self $line) {
            if (in_array((string) DB::table('inspection_plans')->where('id', $line->inspection_plan_id)->value('approval_status'), ['pending', 'approved'], true)) {
                throw new LogicException('The checks of a plan that is waiting for approval or approved cannot be changed.');
            }
        };

        static::updating($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return ['min_value' => 'decimal:4', 'max_value' => 'decimal:4', 'is_critical' => 'boolean'];
    }
}
