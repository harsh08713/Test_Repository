<?php

namespace App\Modules\Industry\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * An allowed move between two workflow states. `industry_template_id` null is the default workflow.
 *
 * @property string $from_state
 * @property string $to_state
 * @property string $action
 * @property string|null $required_permission
 * @property bool $requires_comment
 * @property bool $requires_approval
 */
#[Fillable(['industry_template_id', 'version', 'from_state', 'to_state', 'action', 'required_permission', 'requires_comment', 'requires_approval'])]
class WorkflowTransition extends Model
{
    protected $table = 'workflow_transitions';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['requires_comment' => 'boolean', 'requires_approval' => 'boolean'];
    }
}
