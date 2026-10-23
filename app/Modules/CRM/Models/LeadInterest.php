<?php

namespace App\Modules\CRM\Models;

use App\Modules\CRM\Policies\LeadChildPolicy;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\Unit;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something the lead is interested in: an item from the master (approved and active when chosen) or, when it is not in
 * the master yet, a description in words — with a rough quantity where known. Not a price, and not an order line.
 *
 * @property int $lead_id
 * @property int|null $item_id
 * @property string|null $description
 * @property string|null $quantity
 * @property int|null $unit_id
 * @property string|null $expected_rate what the customer expects to pay: only an existing-customer inquiry's lines carry one
 */
#[Fillable(['description', 'quantity'])]
#[UsePolicy(LeadChildPolicy::class)]
class LeadInterest extends TenantModel
{
    use ValidatesParentCompany;

    /** An interest has no active/inactive `status`: it is on the lead or it is removed. */
    protected $attributes = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'expected_rate' => 'decimal:2'];
    }

    /** @return BelongsTo<Lead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    /** @return BelongsTo<Item, $this> */
    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    /** What to show: the item's name, or the description typed in. */
    public function label(): string
    {
        return (string) ($this->item->name ?? $this->description ?? '');
    }

    protected function companyScopedParents(): array
    {
        return ['lead_id' => Lead::class, 'item_id' => Item::class, 'unit_id' => Unit::class];
    }

    protected function auditLogName(): string
    {
        return 'lead_interests';
    }
}
