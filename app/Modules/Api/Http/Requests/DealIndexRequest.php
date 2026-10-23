<?php

namespace App\Modules\Api\Http\Requests;

use App\Modules\CRM\Enums\DealStage;
use App\Modules\Shared\Enums\ApprovalStatus;
use Illuminate\Validation\Rule;

class DealIndexRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->pagingRules(),
            'q' => ['nullable', 'string', 'max:100'],
            'stage' => ['nullable', 'string', Rule::in(['open', 'all', ...DealStage::values()])],
            'owner' => ['nullable', 'string', 'regex:/^(me|\d{1,18})$/'],
            'branch' => ['nullable', 'integer', 'min:1'],
            'approval' => ['nullable', 'string', Rule::in(array_map(fn (ApprovalStatus $s) => $s->value, ApprovalStatus::cases()))],
            'closing' => ['nullable', 'string', Rule::in(['late', 'month'])],
        ];
    }
}
