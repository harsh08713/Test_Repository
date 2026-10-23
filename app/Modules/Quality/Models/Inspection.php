<?php

namespace App\Modules\Quality\Models;

use App\Models\User;
use App\Modules\Inventory\Models\Batch;
use App\Modules\Masters\Models\Item;
use App\Modules\Quality\Policies\InspectionPolicy;
use App\Modules\Shared\Models\Concerns\BelongsToLocation;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One inspection of one lot against an approved plan. The parameters are copied from the plan when it starts. Its state moves only through InspectionService:
 * open → accepted (everything within limits) or failed → rejected / accepted with a deviation; or cancelled while open.
 *
 * @property string $inspection_number
 * @property int $location_id
 * @property int $inspection_plan_id
 * @property int $item_id
 * @property string $item_name
 * @property string $stage
 * @property string|null $source_type
 * @property int|null $source_id
 * @property string|null $source_label
 * @property int|null $batch_id
 * @property bool $held_batch
 * @property string $lot_quantity
 * @property string $state open|accepted|failed|rejected|deviation|cancelled
 * @property int|null $inspector_id
 * @property Carbon|null $completed_at
 * @property int|null $decided_by
 * @property Carbon|null $decided_at
 * @property string|null $decision_reason
 */
#[UsePolicy(InspectionPolicy::class)]
class Inspection extends TenantModel
{
    use BelongsToLocation, ValidatesParentCompany;

    public const STATES = [
        'open' => 'In progress', 'accepted' => 'Accepted', 'failed' => 'Failed: waiting for a decision', 'rejected' => 'Rejected', 'deviation' => 'Accepted with a deviation', 'cancelled' => 'Cancelled',
    ];

    protected $attributes = ['state' => 'open'];

    protected static function booted(): void
    {
        static::updating(function (self $i) {
            if (in_array((string) $i->getRawOriginal('state'), ['accepted', 'rejected', 'deviation', 'cancelled'], true)) {
                throw new LogicException('A finished inspection does not change.');
            }
        });

        static::deleting(fn () => throw new LogicException('An inspection is never deleted: cancel it instead.'));
    }

    protected function casts(): array
    {
        return ['lot_quantity' => 'decimal:3', 'held_batch' => 'boolean', 'completed_at' => 'datetime', 'decided_at' => 'datetime'];
    }

    /** @return BelongsTo<InspectionPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(InspectionPlan::class, 'inspection_plan_id');
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Batch, $this> */
    public function batch(): BelongsTo
    {
        return $this->belongsTo(Batch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspector_id');
    }

    /** @return HasMany<InspectionResult, $this> */
    public function results(): HasMany
    {
        return $this->hasMany(InspectionResult::class)->orderBy('line_no');
    }

    public function isOpen(): bool
    {
        return $this->state === 'open';
    }

    public function isFinished(): bool
    {
        return in_array($this->state, ['accepted', 'rejected', 'deviation', 'cancelled'], true);
    }

    protected function companyScopedParents(): array
    {
        return ['inspection_plan_id' => InspectionPlan::class, 'item_id' => Item::class, 'batch_id' => Batch::class];
    }
}
