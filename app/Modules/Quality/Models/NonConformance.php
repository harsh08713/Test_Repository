<?php

namespace App\Modules\Quality\Models;

use App\Modules\Inventory\Models\Batch;
use App\Modules\Loss\Models\CorrectiveAction;
use App\Modules\Loss\Models\LossIncident;
use App\Modules\Masters\Models\Item;
use App\Modules\Quality\Policies\NonConformancePolicy;
use App\Modules\Shared\Models\Concerns\BelongsToLocation;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Something that did not meet its requirement, what was decided about it, and the loss incident and corrective action it led to. Changed only through NonConformanceService.
 *
 * @property string $ncr_number
 * @property int $location_id
 * @property int|null $inspection_id
 * @property int $item_id
 * @property string $item_name
 * @property int|null $batch_id
 * @property string $quantity_affected
 * @property string $severity minor|major|critical
 * @property string $description
 * @property string $state open|decided|closed
 * @property string|null $disposition rework|scrap|use_as_is|return_to_supplier|sort
 * @property string|null $disposition_note
 * @property int|null $loss_incident_id
 * @property int|null $corrective_action_id
 * @property Carbon|null $closed_at
 * @property string|null $closing_note
 */
#[UsePolicy(NonConformancePolicy::class)]
class NonConformance extends TenantModel
{
    use BelongsToLocation, ValidatesParentCompany;

    public const SEVERITIES = ['minor' => 'Minor', 'major' => 'Major', 'critical' => 'Critical'];

    public const DISPOSITIONS = ['rework' => 'Rework', 'scrap' => 'Scrap', 'use_as_is' => 'Use as is (a deviation)', 'return_to_supplier' => 'Return to the supplier', 'sort' => 'Sort and keep the good'];

    public const STATES = ['open' => 'Open', 'decided' => 'Decided', 'closed' => 'Closed'];

    protected $attributes = ['state' => 'open', 'quantity_affected' => '0.000'];

    protected static function booted(): void
    {
        static::updating(function (self $n) {
            if ((string) $n->getRawOriginal('state') === 'closed') {
                throw new LogicException('A closed non-conformance report does not change.');
            }
        });

        static::deleting(fn () => throw new LogicException('A non-conformance report is never deleted.'));
    }

    protected function casts(): array
    {
        return ['quantity_affected' => 'decimal:3', 'dispositioned_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    /** @return BelongsTo<Inspection, $this> */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
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

    /** @return BelongsTo<LossIncident, $this> */
    public function lossIncident(): BelongsTo
    {
        return $this->belongsTo(LossIncident::class);
    }

    /** @return BelongsTo<CorrectiveAction, $this> */
    public function correctiveAction(): BelongsTo
    {
        return $this->belongsTo(CorrectiveAction::class);
    }

    protected function companyScopedParents(): array
    {
        return ['item_id' => Item::class, 'batch_id' => Batch::class, 'inspection_id' => Inspection::class, 'loss_incident_id' => LossIncident::class, 'corrective_action_id' => CorrectiveAction::class];
    }
}
