<?php

namespace App\Modules\HR\Models;

use App\Modules\HR\Policies\ChecklistPolicy;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;

/**
 * One line of the company's standard onboarding or exit list.
 *
 * @property string $name
 * @property string $kind onboarding | exit
 * @property string $responsible hr | manager | employee
 * @property int $due_days days after the list is started
 */
#[Fillable(['code', 'name', 'kind', 'responsible', 'due_days', 'status', 'remarks'])]
#[UsePolicy(ChecklistPolicy::class)]
class ChecklistTemplateItem extends TenantModel
{
    public const KINDS = ['onboarding' => 'Onboarding (when someone joins)', 'exit' => 'Exit (when someone gives notice)'];

    public const RESPONSIBLE = ['hr' => 'HR', 'manager' => 'The reporting manager', 'employee' => 'The employee'];

    protected function casts(): array
    {
        return ['due_days' => 'integer'];
    }
}
