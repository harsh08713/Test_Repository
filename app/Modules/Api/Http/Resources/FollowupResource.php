<?php

namespace App\Modules\Api\Http\Resources;

use App\Modules\Api\Support\ApiTime;
use App\Modules\CRM\Models\Followup;
use App\Modules\CRM\Models\Lead;
use Illuminate\Http\Request;

/**
 * A follow-up: what is to be done (or was done) with a lead, when, by whom, and what came of it. A cancelled follow-up is
 * still listed when changes are being synced — its `status` is how the app learns it was called off.
 *
 * @mixin Followup
 */
class FollowupResource extends ApiResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var Followup $followup */
        $followup = $this->resource;
        $subject = $followup->relationLoaded('subject') ? $followup->subject : null;

        return [
            'id' => $followup->getKey(),
            'lead' => $subject instanceof Lead ? ['id' => $subject->getKey(), 'lead_number' => $subject->lead_number, 'name' => $subject->name] : ['id' => (int) $followup->subject_id, 'lead_number' => null, 'name' => null],
            'scheduled_at' => ApiTime::iso($followup->scheduled_at),
            'type' => ['value' => $followup->type->value, 'label' => $followup->type->label()],
            'status' => ['value' => $followup->status->value, 'label' => $followup->status->label()],
            'is_overdue' => $followup->isOverdue(),
            'assigned_to' => ['id' => (int) $followup->assigned_to, 'name' => $followup->relationLoaded('assignee') ? $followup->assignee?->name : null],
            'outcome' => $followup->outcome_id === null ? null : ['id' => (int) $followup->outcome_id, 'name' => $followup->relationLoaded('outcome') ? $followup->outcome?->name : null],
            'notes' => $followup->notes,
            'completed_at' => ApiTime::iso($followup->completed_at),
            'previous_id' => $followup->previous_id,
            'created_at' => ApiTime::iso($followup->created_at),
            'updated_at' => ApiTime::iso($followup->updated_at),
        ];
    }
}
