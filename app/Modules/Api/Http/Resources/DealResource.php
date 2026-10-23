<?php

namespace App\Modules\Api\Http\Resources;

use App\Modules\Api\Support\ApiTime;
use App\Modules\CRM\Models\Deal;
use App\Modules\CRM\Models\DealItem;
use App\Modules\CRM\Models\DealStageEntry;
use Illuminate\Http\Request;

/**
 * A deal as the app sees it. Money is a decimal string ("1568.75"), times are ISO 8601 with an offset. The value, the amounts
 * and the weighted value are the server's own arithmetic: a client never sends them.
 *
 * @mixin Deal
 */
class DealResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Deal $deal */
        $deal = $this->resource;

        return [
            'id' => $deal->getKey(),
            'deal_number' => $deal->deal_number,
            'deal_date' => $deal->deal_date->toDateString(),
            'party_name' => $deal->party_name,
            'contact_person' => $deal->contact_person,
            'mobile' => $deal->mobile,
            'email' => $deal->email,
            'customer' => $deal->customer_ledger_id === null ? null : [
                'id' => (int) $deal->customer_ledger_id,
                'code' => $deal->relationLoaded('customer') ? $deal->customer?->code : null,
                'name' => $deal->relationLoaded('customer') ? $deal->customer?->name : null,
            ],
            'lead' => ['id' => (int) $deal->lead_id, 'lead_number' => $deal->relationLoaded('lead') ? $deal->lead?->lead_number : null],
            'expected_close_date' => $deal->expected_close_date->toDateString(),
            'probability' => $deal->probability,
            'expected_value' => $deal->expected_value,
            'weighted_value' => $deal->weightedValue(),
            'stage' => ['value' => $deal->stage->value, 'label' => $deal->stage->label()],
            'is_open' => $deal->isOpen(),
            'approval_status' => ['value' => $deal->approval_status->value, 'label' => $deal->approval_status->label()],
            'lost' => $deal->lost_at === null ? null : [
                'at' => ApiTime::iso($deal->lost_at),
                'reason' => $deal->lost_reason_id === null ? null : ['id' => (int) $deal->lost_reason_id, 'name' => $deal->relationLoaded('lostReason') ? $deal->lostReason?->name : null],
                'remarks' => $deal->lost_remarks,
            ],
            'branch' => ['id' => $deal->location_id, 'code' => $deal->relationLoaded('location') ? $deal->location?->code : null, 'name' => $deal->relationLoaded('location') ? $deal->location?->name : null],
            'owner' => ['id' => (int) $deal->assigned_to, 'name' => $deal->relationLoaded('assignee') ? $deal->assignee?->name : null],
            'remarks' => $deal->remarks,
            'created_at' => ApiTime::iso($deal->created_at),
            'updated_at' => ApiTime::iso($deal->updated_at),
            'items' => $this->whenLoaded('items', fn () => $deal->items->map(fn (DealItem $line) => [
                'id' => $line->getKey(),
                'line_no' => $line->line_no,
                'lead_interest_id' => $line->lead_interest_id,
                'item' => ['id' => (int) $line->item_id, 'code' => $line->relationLoaded('item') ? $line->item?->code : null, 'name' => $line->relationLoaded('item') ? $line->item?->name : null],
                'unit' => ['id' => (int) $line->unit_id, 'code' => $line->relationLoaded('unit') ? $line->unit?->code : null],
                'quantity' => $line->quantity,
                'expected_rate' => $line->expected_rate,
                'amount' => $line->amount,
            ])->values()->all()),
            'stage_history' => $this->whenLoaded('stageHistory', fn () => $deal->stageHistory->map(fn (DealStageEntry $entry) => [
                'from' => $entry->from_stage?->value,
                'to' => $entry->to_stage->value,
                'comment' => $entry->comment,
                'reason' => $entry->lost_reason_id === null ? null : ['id' => (int) $entry->lost_reason_id, 'name' => $entry->relationLoaded('lostReason') ? $entry->lostReason?->name : null],
                'by' => $entry->actor_id === null ? null : ['id' => (int) $entry->actor_id, 'name' => $entry->relationLoaded('actor') ? $entry->actor?->name : null],
                'at' => ApiTime::iso($entry->created_at),
            ])->values()->all()),
            'permissions' => $this->when(isset($this->additional['permissions']), fn () => $this->additional['permissions']),
        ];
    }
}
