<?php

namespace App\Modules\Api\Http\Requests;

/** Paged, optionally searched, optionally "only what changed since" — for the lists an app caches. */
class LookupRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->pagingRules(),
            'q' => ['nullable', 'string', 'max:100'],
        ];
    }
}
