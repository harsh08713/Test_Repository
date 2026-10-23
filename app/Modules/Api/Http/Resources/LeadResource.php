<?php

namespace App\Modules\Api\Http\Resources;

use App\Modules\Api\Support\ApiTime;
use App\Modules\CRM\Models\Lead;
use App\Modules\CRM\Models\LeadInterest;
use Illuminate\Http\Request;

/**
 * A lead as the app sees it. Money is a decimal string ("250000.00"), times are ISO 8601 with an offset, and `deleted_at`
 * is present only when the lead has been removed (a sync that asks for changes since a moment receives those too, so the
 * app can drop them).
 *
 * @mixin Lead
 */
class LeadResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Lead $lead */
        $lead = $this->resource;

        return [
            'id' => $lead->getKey(),
            'lead_number' => $lead->lead_number,
            'lead_date' => $lead->lead_date->toDateString(),
            'name' => $lead->name,
            'contact_person' => $lead->contact_person,
            'mobile' => $lead->mobile,
            'phone' => $lead->phone,
            'email' => $lead->email,
            'address' => [
                'line1' => $lead->address_line1,
                'line2' => $lead->address_line2,
                'city' => $lead->city,
                'pin_code' => $lead->pin_code,
                'state' => $this->named($lead->state_id, $lead->relationLoaded('state') ? $lead->state?->name : null),
                'district' => $this->named($lead->district_id, $lead->relationLoaded('district') ? $lead->district?->name : null),
                'block' => $this->named($lead->block_id, $lead->relationLoaded('block') ? $lead->block?->name : null),
            ],
            'requirement' => $lead->requirement,
            'expected_value' => $lead->expected_value,
            'type' => $lead->lead_type->value,
            'customer' => $lead->customer_ledger_id === null ? null : [
                'id' => (int) $lead->customer_ledger_id,
                'code' => $lead->relationLoaded('customer') ? $lead->customer?->code : null,
                'name' => $lead->relationLoaded('customer') ? $lead->customer?->name : null,
            ],
            'approval_status' => $lead->isExisting() ? ['value' => $lead->approval_status->value, 'label' => $lead->approval_status->label()] : null,
            'deal' => $lead->relationLoaded('deal') && $lead->deal !== null ? [
                'id' => (int) $lead->deal->getKey(), 'deal_number' => $lead->deal->deal_number, 'stage' => $lead->deal->stage->value,
            ] : null,
            'stage' => ['value' => $lead->stage->value, 'label' => $lead->stage->label()],
            'is_open' => $lead->isOpen(),
            'is_complete' => $lead->is_complete,
            'channel' => $lead->entry_channel->value,
            'source' => $this->named($lead->lead_source_id, $lead->relationLoaded('source') ? $lead->source?->name : null),
            'branch' => ['id' => $lead->location_id, 'code' => $lead->relationLoaded('location') ? $lead->location?->code : null, 'name' => $lead->relationLoaded('location') ? $lead->location?->name : null],
            'owner' => $lead->assigned_to === null ? null : $this->named($lead->assigned_to, $lead->relationLoaded('assignee') ? $lead->assignee?->name : null),
            'next_followup_at' => ApiTime::iso($lead->next_followup_at),
            'last_contacted_at' => ApiTime::iso($lead->last_contacted_at),
            'lost' => $lead->lost_at === null ? null : [
                'at' => ApiTime::iso($lead->lost_at),
                'reason' => $this->named($lead->lost_reason_id, $lead->relationLoaded('lostReason') ? $lead->lostReason?->name : null),
                'remarks' => $lead->lost_remarks,
            ],
            'position' => $lead->latitude === null ? null : ['latitude' => (float) $lead->latitude, 'longitude' => (float) $lead->longitude],
            'remarks' => $lead->remarks,
            'created_at' => ApiTime::iso($lead->created_at),
            'updated_at' => ApiTime::iso($lead->updated_at),
            'deleted_at' => $lead->deleted_at === null ? null : ApiTime::iso($lead->deleted_at),
            'interests' => $this->whenLoaded('interests', fn () => $lead->interests->map(fn (LeadInterest $row) => [
                'id' => $row->getKey(),
                'item' => $row->item_id === null ? null : ['id' => $row->item_id, 'code' => $row->item?->code, 'name' => $row->item?->name],
                'description' => $row->description,
                'quantity' => $row->quantity,
                'expected_rate' => $row->expected_rate,
                'unit' => $row->unit_id === null ? null : ['id' => $row->unit_id, 'code' => $row->unit?->code],
            ])->values()->all()),
            'permissions' => $this->when(isset($this->additional['permissions']), fn () => $this->additional['permissions']),
        ];
    }

    /** @return array{id: int, name: string|null}|null */
    private function named(mixed $id, ?string $name): ?array
    {
        return $id === null ? null : ['id' => (int) $id, 'name' => $name];
    }
}
