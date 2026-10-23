<?php

namespace App\Modules\Industry\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * A state of the loss workflow. `industry_template_id` null is the default workflow used by every template without its own.
 *
 * @property string $code
 * @property string $name
 * @property bool $is_initial
 * @property bool $is_final
 * @property string|null $color
 * @property int $sort_order
 */
#[Fillable(['industry_template_id', 'version', 'code', 'name', 'is_initial', 'is_final', 'color', 'sort_order'])]
class WorkflowState extends Model
{
    protected $table = 'workflow_states';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_initial' => 'boolean', 'is_final' => 'boolean'];
    }
}
