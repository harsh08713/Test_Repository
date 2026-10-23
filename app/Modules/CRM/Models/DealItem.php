<?php

namespace App\Modules\CRM\Models;

use App\Modules\CRM\Policies\DealChildPolicy;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\Unit;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a deal: an approved item, the unit it is sold in, how many and the rate expected. `amount` is quantity ×
 * rate rounded half up to paise — worked out by DealService from the other three and never taken from a client.
 *
 * @property int $deal_id
 * @property int $line_no
 * @property int|null $lead_interest_id the line of the lead this one came from
 * @property int $item_id
 * @property int $unit_id
 * @property string $quantity
 * @property string $expected_rate
 * @property string $amount
 */
#[UsePolicy(DealChildPolicy::class)]
class DealItem extends TenantModel
{
    use ValidatesParentCompany;

    /** A line has no active/inactive `status`: it is on the deal or it is removed. */
    protected $attributes = [];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'expected_rate' => 'decimal:2', 'amount' => 'decimal:2', 'line_no' => 'integer'];
    }

    /** @return BelongsTo<Deal, $this> */
    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
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

    /** @return BelongsTo<LeadInterest, $this> */
    public function interest(): BelongsTo
    {
        return $this->belongsTo(LeadInterest::class, 'lead_interest_id');
    }

    protected function companyScopedParents(): array
    {
        return ['deal_id' => Deal::class, 'item_id' => Item::class, 'unit_id' => Unit::class, 'lead_interest_id' => LeadInterest::class];
    }

    protected function auditLogName(): string
    {
        return 'deal_items';
    }
}
