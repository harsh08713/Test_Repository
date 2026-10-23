<?php

namespace App\Modules\Sales\Models;

use App\Modules\CRM\Models\DealItem;
use App\Modules\CRM\Models\LeadInterest;
use App\Modules\Masters\Models\Item;
use App\Modules\Masters\Models\Rate;
use App\Modules\Masters\Models\Unit;
use App\Modules\Sales\Enums\QuotationState;
use App\Modules\Sales\Policies\QuotationChildPolicy;
use App\Modules\Shared\Models\Concerns\ValidatesParentCompany;
use App\Modules\Shared\Models\TenantModel;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One line of a quotation revision. `list_rate` is what the rate master answered, `rate` what the line is quoted at (the same,
 * or an overridden one with its reason); every amount is worked out by TaxService and kept. A line of a revision that has left
 * draft cannot be added, changed or removed.
 *
 * @property int $quotation_id
 * @property int $line_no
 * @property int|null $deal_item_id
 * @property int|null $lead_interest_id
 * @property int $item_id
 * @property int $unit_id
 * @property string $item_name
 * @property string|null $hsn_code
 * @property string $quantity
 * @property int|null $rate_id
 * @property string $rate_source
 * @property string $list_rate
 * @property string $rate
 * @property bool $rate_overridden
 * @property string|null $override_reason
 * @property string $discount_percent
 * @property string $gross_amount
 * @property string $discount_amount
 * @property string $taxable_amount
 * @property string $gst_rate
 * @property string $cgst_amount
 * @property string $sgst_amount
 * @property string $igst_amount
 * @property string $line_total
 */
#[UsePolicy(QuotationChildPolicy::class)]
class QuotationItem extends TenantModel
{
    use ValidatesParentCompany;

    protected $attributes = [];

    protected static function booted(): void
    {
        $guard = function (self $line) {
            if (Quotation::storedState((int) $line->quotation_id) !== QuotationState::Draft->value) {
                throw new LogicException('The lines of a quotation that has left draft cannot be changed: make a new revision instead.');
            }
        };

        static::creating($guard);
        static::updating($guard);
        static::deleting($guard);
    }

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'quantity' => 'decimal:3',
            'list_rate' => 'decimal:4',
            'rate' => 'decimal:4',
            'rate_overridden' => 'boolean',
            'discount_percent' => 'decimal:2',
            'gross_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'taxable_amount' => 'decimal:2',
            'gst_rate' => 'decimal:2',
            'cgst_amount' => 'decimal:2',
            'sgst_amount' => 'decimal:2',
            'igst_amount' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    /** @return BelongsTo<Quotation, $this> */
    public function quotation(): BelongsTo
    {
        return $this->belongsTo(Quotation::class);
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

    /** @return BelongsTo<Rate, $this> */
    public function rateRow(): BelongsTo
    {
        return $this->belongsTo(Rate::class, 'rate_id');
    }

    /** @return BelongsTo<DealItem, $this> */
    public function dealItem(): BelongsTo
    {
        return $this->belongsTo(DealItem::class);
    }

    /** @return BelongsTo<LeadInterest, $this> */
    public function interest(): BelongsTo
    {
        return $this->belongsTo(LeadInterest::class, 'lead_interest_id');
    }

    protected function companyScopedParents(): array
    {
        return ['quotation_id' => Quotation::class, 'item_id' => Item::class, 'unit_id' => Unit::class];
    }

    protected function auditLogName(): string
    {
        return 'quotation_items';
    }
}
