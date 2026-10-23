<?php

namespace App\Modules\HR\Models;

use App\Modules\HR\Policies\ReviewCyclePolicy;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Support\Carbon;

/**
 * ReviewCycle (H4). Changed only through its service.
 *
 * @property string $name
 * @property Carbon $period_start
 * @property Carbon $period_end
 * @property string $state
 */
#[Fillable(['code', 'name', 'period_start', 'period_end', 'remarks'])]
#[UsePolicy(ReviewCyclePolicy::class)]
class ReviewCycle extends TenantModel
{
    public const STATES = ['draft' => 'Draft', 'open' => 'Open', 'closed' => 'Closed'];

    protected $attributes = ['state' => 'draft'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date'];
    }
}
