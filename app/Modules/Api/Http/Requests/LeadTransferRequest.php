<?php

namespace App\Modules\Api\Http\Requests;

use App\Modules\CRM\Services\LeadTransferService;

class LeadTransferRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lead_ids' => ['required', 'array', 'min:1', 'max:'.LeadTransferService::MAX_PER_TRANSFER],
            'lead_ids.*' => ['integer', 'min:1', 'distinct'],
            'to_user_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
