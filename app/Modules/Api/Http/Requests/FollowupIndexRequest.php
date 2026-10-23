<?php

namespace App\Modules\Api\Http\Requests;

use App\Modules\Api\Support\IsoDateTime;
use App\Modules\CRM\Enums\FollowupStatus;
use App\Modules\CRM\Enums\FollowupType;
use Illuminate\Validation\Rule;

/** The list and the calendar share their filters; the calendar adds the window it is showing. */
class FollowupIndexRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $calendar = $this->routeIs('api.v1.followups.calendar');

        return [
            ...($calendar ? [] : $this->pagingRules()),
            'status' => ['nullable', 'string', Rule::in(['all', 'overdue', 'today', ...array_map(fn (FollowupStatus $s) => $s->value, FollowupStatus::cases())])],
            'type' => ['nullable', 'string', Rule::in(FollowupType::values())],
            'lead_id' => ['nullable', 'integer', 'min:1'],
            'person' => ['nullable', 'integer', 'min:1'],
            'team' => ['nullable', 'integer', 'min:1'],
            'from' => [$calendar ? 'required' : 'nullable', new IsoDateTime],
            'to' => [$calendar ? 'required' : 'nullable', new IsoDateTime],
        ];
    }
}
