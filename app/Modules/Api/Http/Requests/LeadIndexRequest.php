<?php

namespace App\Modules\Api\Http\Requests;

use App\Modules\CRM\Enums\LeadStage;
use Illuminate\Validation\Rule;

class LeadIndexRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->pagingRules(),
            'q' => ['nullable', 'string', 'max:100'],
            'stage' => ['nullable', 'string', Rule::in(['open', 'all', ...LeadStage::values()])],
            'owner' => ['nullable', 'string', 'regex:/^(me|none|\d{1,18})$/'],
            'branch' => ['nullable', 'integer', 'min:1'],
            'source' => ['nullable', 'integer', 'min:1'],
            'type' => ['nullable', 'string', Rule::in(['new', 'existing'])],
            'due' => ['nullable', 'string', Rule::in(['overdue', 'today', 'week', 'none'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
