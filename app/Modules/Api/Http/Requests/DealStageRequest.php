<?php

namespace App\Modules\Api\Http\Requests;

use App\Modules\CRM\Enums\DealStage;
use Illuminate\Validation\Rule;

/** Move a deal to another stage a person may choose (the system sets Quotation and Won itself). */
class DealStageRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'stage' => ['required', 'string', Rule::in(DealStage::values())],
            'comment' => ['nullable', 'string', 'max:1000'],
            'lost_reason_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
