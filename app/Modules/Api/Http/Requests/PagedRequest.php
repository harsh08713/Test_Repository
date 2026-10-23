<?php

namespace App\Modules\Api\Http\Requests;

/** A list with nothing but paging parameters (attendance history, devices). */
class PagedRequest extends ApiRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            ...$this->pagingRules(),
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ];
    }
}
