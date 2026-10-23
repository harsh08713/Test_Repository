<?php

namespace App\Modules\Api\Http\Requests;

use App\Modules\CRM\Enums\LeadStage;
use Illuminate\Validation\Rule;

/** Move a lead to another stage a person may choose (the system sets Deal, Quotation and Won itself). */
class LeadStageRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'stage' => ['required', 'string', Rule::in(LeadStage::values())],
            'comment' => ['nullable', 'string', 'max:1000'],
            'lost_reason_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
