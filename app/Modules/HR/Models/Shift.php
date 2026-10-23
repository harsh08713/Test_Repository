<?php

namespace App\Modules\HR\Models;

use App\Modules\HR\Policies\ShiftPolicy;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;

/**
 * A working shift. Late = punching in after `start_time` plus the grace. A day with at least `full_day_minutes` between the first punch in and the last punch out is
 * present, with at least `half_day_minutes` a half day, otherwise absent. One shift is the company's default (used for anyone without a shift of their own).
 *
 * @property string $name
 * @property string $start_time
 * @property string $end_time
 * @property int $grace_minutes
 * @property int $full_day_minutes
 * @property int $half_day_minutes
 * @property bool $is_default
 */
#[Fillable(['code', 'name', 'start_time', 'end_time', 'grace_minutes', 'full_day_minutes', 'half_day_minutes', 'is_default', 'status', 'remarks'])]
#[UsePolicy(ShiftPolicy::class)]
class Shift extends TenantModel
{
    protected static function booted(): void
    {
        // one default shift: making this one the default takes the mark off the others
        static::saved(function (self $shift) {
            if ($shift->is_default) {
                static::query()->whereKeyNot($shift->id)->where('is_default', true)->update(['is_default' => false]);
            }
        });
    }

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'grace_minutes' => 'integer', 'full_day_minutes' => 'integer', 'half_day_minutes' => 'integer'];
    }
}
