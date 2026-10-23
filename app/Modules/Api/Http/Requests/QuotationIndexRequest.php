<?php

namespace App\Modules\Api\Http\Requests;

use App\Modules\Sales\Enums\QuotationState;
use App\Modules\Shared\Enums\ApprovalStatus;
use Illuminate\Validation\Rule;

class QuotationIndexRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->pagingRules(),
            'q' => ['nullable', 'string', 'max:100'],
            'state' => ['nullable', 'string', Rule::in(['live', 'all', ...QuotationState::values()])],
            'deal_id' => ['nullable', 'integer', 'min:1'],
            'lead_id' => ['nullable', 'integer', 'min:1'],
            'branch' => ['nullable', 'integer', 'min:1'],
            'approval' => ['nullable', 'string', Rule::in(array_map(fn (ApprovalStatus $s) => $s->value, ApprovalStatus::cases()))],
            'latest' => ['nullable', 'boolean'],
        ];
    }
}
