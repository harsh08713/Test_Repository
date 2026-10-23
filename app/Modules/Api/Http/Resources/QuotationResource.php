<?php

namespace App\Modules\Api\Http\Resources;

use App\Modules\Api\Support\ApiTime;
use App\Modules\Sales\Models\Quotation;
use App\Modules\Sales\Models\QuotationItem;
use App\Modules\Sales\Models\QuotationStateEntry;
use Illuminate\Http\Request;

/**
 * A quotation revision as the app sees it, read-only. Money is a decimal string, times are ISO 8601 with an offset. Every figure is the
 * one stored when the draft was saved: the server's own arithmetic, kept as the customer saw it.
 *
 * @mixin Quotation
 */
class QuotationResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Quotation $q */
        $q = $this->resource;

        return [
            'id' => $q->getKey(),
            'quotation_number' => $q->quotation_number,
            'revision_no' => $q->revision_no,
            'display_number' => $q->displayNumber(),
            'is_latest' => $q->isLatestRevision(),
            'state' => ['value' => $q->state->value, 'label' => $q->state->label()],
            'approval_status' => ['value' => $q->approval_status->value, 'label' => $q->approval_status->label()],
            'quotation_date' => $q->quotation_date->toDateString(),
            'valid_until' => $q->valid_until->toDateString(),
            'price_date' => $q->price_date->toDateString(),
            'price_list' => $q->price_list_id === null ? null : ['id' => (int) $q->price_list_id, 'name' => $q->relationLoaded('priceList') ? $q->priceList?->name : null],
            'lead' => ['id' => (int) $q->lead_id],
            'deal' => $q->deal_id === null ? null : ['id' => (int) $q->deal_id, 'deal_number' => $q->relationLoaded('deal') ? $q->deal?->deal_number : null],
            'customer' => $q->customer_ledger_id === null ? null : ['id' => (int) $q->customer_ledger_id],
            'party_name' => $q->party_name,
            'contact_person' => $q->contact_person,
            'mobile' => $q->mobile,
            'email' => $q->email,
            'billing_address' => ['lines' => $q->addressLines(), 'gstin' => $q->customer_gstin],
            'place_of_supply' => [
                'state_id' => (int) $q->place_of_supply_state_id,
                'name' => $q->relationLoaded('placeOfSupply') ? $q->placeOfSupply?->name : null,
                'gst_code' => $q->relationLoaded('placeOfSupply') ? $q->placeOfSupply?->gst_code : null,
            ],
            'supply_type' => $q->supply_type,
            'totals' => [
                'gross' => $q->gross_total, 'discount' => $q->discount_total, 'taxable' => $q->taxable_total, 'cgst' => $q->cgst_total,
                'sgst' => $q->sgst_total, 'igst' => $q->igst_total, 'round_off' => $q->round_off, 'grand_total' => $q->grand_total,
            ],
            'terms' => $q->terms,
            'branch' => ['id' => $q->location_id, 'code' => $q->relationLoaded('location') ? $q->location?->code : null, 'name' => $q->relationLoaded('location') ? $q->location?->name : null],
            'sent_at' => ApiTime::iso($q->sent_at),
            'accepted_at' => ApiTime::iso($q->accepted_at),
            'declined_at' => ApiTime::iso($q->declined_at),
            'expired_at' => ApiTime::iso($q->expired_at),
            'cancelled_at' => ApiTime::iso($q->cancelled_at),
            'created_at' => ApiTime::iso($q->created_at),
            'updated_at' => ApiTime::iso($q->updated_at),
            'items' => $this->whenLoaded('items', fn () => $q->items->map(fn (QuotationItem $line) => [
                'id' => $line->getKey(),
                'line_no' => $line->line_no,
                'item' => ['id' => (int) $line->item_id, 'name' => $line->item_name, 'hsn_code' => $line->hsn_code],
                'unit' => ['id' => (int) $line->unit_id, 'code' => $line->relationLoaded('unit') ? $line->unit?->code : null],
                'quantity' => $line->quantity,
                'list_rate' => $line->list_rate,
                'rate' => $line->rate,
                'rate_overridden' => $line->rate_overridden,
                'discount_percent' => $line->discount_percent,
                'gst_rate' => $line->gst_rate,
                'taxable_amount' => $line->taxable_amount,
                'cgst_amount' => $line->cgst_amount,
                'sgst_amount' => $line->sgst_amount,
                'igst_amount' => $line->igst_amount,
                'line_total' => $line->line_total,
            ])->values()->all()),
            'history' => $this->whenLoaded('stateHistory', fn () => $q->stateHistory->map(fn (QuotationStateEntry $entry) => [
                'from' => $entry->from_state?->value,
                'to' => $entry->to_state->value,
                'comment' => $entry->comment,
                'by' => $entry->actor_id === null ? null : ['id' => (int) $entry->actor_id, 'name' => $entry->relationLoaded('actor') ? $entry->actor?->name : null],
                'at' => ApiTime::iso($entry->created_at),
            ])->values()->all()),
        ];
    }
}
