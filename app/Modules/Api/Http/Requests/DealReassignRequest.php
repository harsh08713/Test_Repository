<?php

namespace App\Modules\Api\Http\Requests;

/** Hand a deal (and its lead) to another person. */
class DealReassignRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'to_user_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'min:3', 'max:1000'],
        ];
    }
}
