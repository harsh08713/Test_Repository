<?php

namespace App\Modules\Api\Http\Requests;

use App\Modules\Masters\Services\LedgerLookup;

/** A search for a customer to name on an inquiry: a few letters of the code, the name or the GSTIN. */
class CustomerLookupRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.LedgerLookup::MAX_LIMIT],
        ];
    }
}
