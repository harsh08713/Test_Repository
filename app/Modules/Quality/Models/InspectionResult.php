<?php

namespace App\Modules\Quality\Models;

use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * One thing checked on one inspection, with the limits it was checked against (copied from the plan). `passed` is worked out by InspectionService from the limits, never typed.
 * Changed only while the inspection is open; kept as a record afterwards.
 *
 * @property int $inspection_id
 * @property int $line_no
 * @property string $name
 * @property string $kind
 * @property string|null $unit_text
 * @property string|null $min_value
 * @property string|null $max_value
 * @property bool $is_critical
 * @property string|null $measured_value
 * @property bool|null $passed
 * @property string|null $note
 */
class InspectionResult extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id', 'company_id'];

    protected function casts(): array
    {
        return ['min_value' => 'decimal:4', 'max_value' => 'decimal:4', 'measured_value' => 'decimal:4', 'is_critical' => 'boolean', 'passed' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $r) {
            if (DB::table('inspections')->where('id', $r->inspection_id)->value('state') !== 'open') {
                throw new LogicException('The results of a finished inspection are a record and cannot be changed.');
            }

            if ($r->isDirty(['inspection_id', 'line_no', 'name', 'kind', 'unit_text', 'min_value', 'max_value', 'is_critical', 'company_id'])) {
                throw new LogicException('What was checked, and its limits, cannot be changed.');
            }
        });

        static::deleting(fn () => throw new LogicException('Results are a record and cannot be deleted.'));
    }
}
