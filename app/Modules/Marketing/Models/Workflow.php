<?php

namespace App\Modules\Marketing\Models;

use App\Models\User;
use App\Modules\Marketing\Policies\WorkflowPolicy;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An automation: when something happens (the trigger), and the facts match (the conditions), do these things (the actions). What it does lives in
 * its versions — a change is a new version, and the old ones stay. It runs as `run_as`, the person who last saved it: every action is checked against
 * what that person may do, so a workflow can never do what its author could not. Written only by WorkflowService.
 *
 * @property int $id
 * @property string $state draft | active | paused
 * @property string $name
 * @property string|null $description
 * @property string $trigger
 * @property bool $dry_run
 * @property int|null $current_version_id
 * @property int $run_as
 * @property int $max_runs_per_hour
 * @property Carbon|null $activated_at
 */
#[Fillable(['name', 'description', 'remarks'])]
#[UsePolicy(WorkflowPolicy::class)]
class Workflow extends TenantModel
{
    /** The table has no active/inactive `status`: a workflow has its own `state`. */
    protected $attributes = ['state' => 'draft'];

    protected function casts(): array
    {
        return ['dry_run' => 'boolean', 'activated_at' => 'datetime'];
    }

    /** @return HasMany<WorkflowVersion, $this> */
    public function versions(): HasMany
    {
        return $this->hasMany(WorkflowVersion::class)->orderByDesc('version');
    }

    /** @return BelongsTo<WorkflowVersion, $this> */
    public function current(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'current_version_id');
    }

    /** @return HasMany<WorkflowRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class)->orderByDesc('id');
    }

    /** @return BelongsTo<User, $this> */
    public function runner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'run_as');
    }

    public function isActive(): bool
    {
        return $this->state === 'active';
    }

    protected function auditLogName(): string
    {
        return 'workflows';
    }
}
