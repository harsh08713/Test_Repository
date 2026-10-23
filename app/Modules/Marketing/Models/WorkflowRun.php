<?php

namespace App\Modules\Marketing\Models;

use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One time a workflow ran for one occurrence of its trigger. `unique(workflow_id, trigger_key)` is what makes "the same lead's creation never runs the
 * same workflow twice" a database fact. A failed run can be retried (its attempts count up, the outcome is rewritten); an ok run never changes.
 *
 * @property int $id
 * @property int $workflow_id
 * @property int $workflow_version_id
 * @property string $trigger_key
 * @property string $subject_type lead | deal | quotation | order
 * @property int $subject_id
 * @property string $status ok | failed | skipped | dry_run
 * @property string|null $reason
 * @property array<int, array<string, mixed>>|null $results
 * @property int $attempts
 * @property Carbon $executed_at
 */
class WorkflowRun extends Model
{
    use BelongsToCompany;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['results' => 'array', 'executed_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $run) {
            if ($run->getRawOriginal('status') === 'ok') {
                throw new LogicException('A run that succeeded is a record and does not change.');
            }
        });

        static::deleting(fn () => throw new LogicException('Workflow runs are kept as a record and cannot be deleted.'));
    }

    /** @return BelongsTo<Workflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /** @return BelongsTo<WorkflowVersion, $this> */
    public function version(): BelongsTo
    {
        return $this->belongsTo(WorkflowVersion::class, 'workflow_version_id');
    }
}
