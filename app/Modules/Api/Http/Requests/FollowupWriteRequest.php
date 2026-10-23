<?php

namespace App\Modules\Api\Http\Requests;

use App\Modules\Api\Support\IsoDateTime;
use App\Modules\CRM\Enums\FollowupType;
use Illuminate\Validation\Rule;

/**
 * Plan, log, complete, move and call off a follow-up share one request class: the route says which, and the rules follow.
 * Times are ISO 8601 (a time without an offset is company wall-clock time).
 */
class FollowupWriteRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $type = ['required', 'string', Rule::in(FollowupType::values())];
        $next = [
            'next_at' => ['nullable', new IsoDateTime],
            'next_type' => ['nullable', 'string', Rule::in(FollowupType::values())],
        ];

        return match (true) {
            $this->routeIs('api.v1.leads.followups.plan') => [
                'scheduled_at' => ['required', new IsoDateTime],
                'type' => $type,
                'assigned_to' => ['nullable', 'integer', 'min:1'],
                'notes' => ['nullable', 'string', 'max:2000'],
            ],
            $this->routeIs('api.v1.leads.followups.log') => [
                'type' => $type,
                'outcome_id' => ['required', 'integer', 'min:1'],
                'notes' => ['nullable', 'string', 'max:2000'],
                ...$next,
            ],
            $this->routeIs('api.v1.followups.complete') => [
                'outcome_id' => ['required', 'integer', 'min:1'],
                'notes' => ['nullable', 'string', 'max:2000'],
                ...$next,
            ],
            $this->routeIs('api.v1.followups.reschedule') => [
                'scheduled_at' => ['required', new IsoDateTime],
            ],
            $this->routeIs('api.v1.followups.cancel') => [
                'reason' => ['required', 'string', 'min:3', 'max:500'],
            ],
            default => [],
        };
    }
}
