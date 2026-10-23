<?php

namespace App\Modules\Marketing\Models;

use App\Models\User;
use App\Modules\Shared\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One saved definition of a workflow: `{conditions: [...], actions: [...], params: {...}}`. Immutable — editing a workflow saves a new version.
 *
 * @property int $id
 * @property int $workflow_id
 * @property int $version
 * @property array<string, mixed> $definition
 * @property int|null $saved_by
 * @property Carbon $created_at
 */
class WorkflowVersion extends Model
{
    use BelongsToCompany;

    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['definition' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A workflow version never changes; save a new version instead.'));
        static::deleting(fn () => throw new LogicException('Workflow versions are kept as a record and cannot be deleted.'));
    }

    /** @return BelongsTo<Workflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /** @return BelongsTo<User, $this> */
    public function saver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'saved_by');
    }
}
